<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

/**
 * v3.7.0 (Posteingang) — erfundene Versorgerpost für die Tests: Textebene je
 * Seite, wie pdf.js sie liefert, und Antworten, wie ein Modell sie gäbe.
 * Anbieter, Nummern und Beträge sind ausgedacht; die Marktlokation ist eine
 * synthetische Nummer mit gültiger Prüfziffer.
 */
final class InboxFixture
{
    public const MALO = '51234567895';
    public const METER = 'TEST-4711-01';

    /** @return list<string> */
    public static function invoicePages(): array
    {
        return [
            "Stadtwerke Musterstadt GmbH · Beispielweg 1 · 04109 Musterstadt\nJahresabrechnung Erdgas 2025\n"
            . "Kundennummer: K-100200\nVertragskonto: VK-778899\nAbrechnungszeitraum: 01.01.2025 bis 31.12.2025\n"
            . "Zählernummer: TEST-4711-01\nMarktlokation: 51234567895\n"
            . "Zählerstand alt am 31.12.2024: 12.345 m³\nZählerstand neu am 31.12.2025: 13.580 m³\n"
            . "Verbrauch 1.235 m³ × Zustandszahl 0,9512 × Brennwert 11,250 kWh/m³ = 13.216 kWh\n"
            . "Zustandszahl 0,9512 und Brennwert 11,250 kWh/m³ vom 01.01.2025 bis 31.12.2025\n"
            . "Rechnungsbetrag brutto: 1.722,70 €",
            "Geleistete Abschläge\nAbschläge Januar bis Juni 2025: 6 × 140,00 € = 840,00 €\n"
            . "Abschläge Juli bis Dezember 2025: 6 × 140,00 € = 840,00 €\nSumme der Abschläge: 1.680,00 €\n"
            . "Nachzahlung: 42,70 €\nIhr neuer Abschlag ab 01.02.2026: 145,00 € monatlich\n"
            . "Arbeitspreis ab 01.01.2025: 11,90 ct/kWh\nGrundpreis: 12,50 € pro Monat",
            "Allgemeine Geschäftsbedingungen\n§ 1 Geltungsbereich … § 2 Vertragsschluss … § 3 Preise … § 4 Abrechnung … § 5 Zahlung … § 6 Kündigung …",
        ];
    }

    /** Antwort eines Modells auf die Rechnung (Werte wie gedruckt, mit Zitat und Seite). */
    public static function invoiceAnswer(): array
    {
        $f = fn($v, string $q, int $p = 1) => ['value' => $v, 'quote' => $q, 'page' => $p];
        return [
            'utility' => $f('gas', 'Jahresabrechnung Erdgas 2025'),
            'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'kind' => $f('annual', 'Jahresabrechnung Erdgas 2025'),
            'period_from' => $f('01.01.2025', 'Abrechnungszeitraum: 01.01.2025 bis 31.12.2025'),
            'period_to' => $f('31.12.2025', 'Abrechnungszeitraum: 01.01.2025 bis 31.12.2025'),
            'consumption' => $f('13.216', '= 13.216 kWh'),
            'reading_new' => $f('13.580', 'Zählerstand neu am 31.12.2025: 13.580 m³'),
            'reading_new_date' => $f('31.12.2025', 'Zählerstand neu am 31.12.2025: 13.580 m³'),
            'reading_old' => $f('12.345', 'Zählerstand alt am 31.12.2024: 12.345 m³'),
            'reading_old_date' => $f('31.12.2024', 'Zählerstand alt am 31.12.2024: 12.345 m³'),
            'total_amount' => $f('1.722,70', 'Rechnungsbetrag brutto: 1.722,70 €'),
            'advances_paid' => ['value' => [
                ['amount' => '840,00', 'date' => null, 'quote' => 'Abschläge Januar bis Juni 2025: 6 × 140,00 € = 840,00 €', 'page' => 2],
                ['amount' => '840,00', 'date' => null, 'quote' => 'Abschläge Juli bis Dezember 2025: 6 × 140,00 € = 840,00 €', 'page' => 2],
            ], 'quote' => null, 'page' => null],
            'advances_total' => $f('1.680,00', 'Summe der Abschläge: 1.680,00 €', 2),
            'result_kind' => $f('due', 'Nachzahlung: 42,70 €', 2),
            'result_amount' => $f('42,70', 'Nachzahlung: 42,70 €', 2),
            'advance_new' => $f('145,00', 'Ihr neuer Abschlag ab 01.02.2026: 145,00 €', 2),
            'advance_new_from' => $f('01.02.2026', 'Ihr neuer Abschlag ab 01.02.2026', 2),
            'meter_number' => $f(self::METER, 'Zählernummer: TEST-4711-01'),
            'market_location' => $f(self::MALO, 'Marktlokation: 51234567895'),
            'contract_number' => $f('VK-778899', 'Vertragskonto: VK-778899'),
            'customer_number' => $f('K-100200', 'Kundennummer: K-100200'),
            'gas_factors' => ['value' => [
                ['from' => '01.01.2025', 'to' => '31.12.2025', 'z' => '0,9512', 'hs' => '11,250',
                 'quote' => 'Zustandszahl 0,9512 und Brennwert 11,250 kWh/m³ vom 01.01.2025 bis 31.12.2025', 'page' => 1],
            ], 'quote' => null, 'page' => null],
            'working_prices' => ['value' => [['from' => '01.01.2025', 'ct' => '11,90', 'quote' => 'Arbeitspreis ab 01.01.2025: 11,90 ct/kWh', 'page' => 2]]],
            'base_price' => $f('12,50', 'Grundpreis: 12,50 € pro Monat', 2),
            'base_price_period' => $f('month', 'Grundpreis: 12,50 € pro Monat', 2),
        ];
    }

    /** @return list<string> */
    public static function confirmationPages(): array
    {
        return ["Musterenergie Nord AG\nVertragsbestätigung Strom\nWillkommen! Ihr Lieferbeginn ist der 01.03.2026.\n"
            . "Tarif: Ökostrom Fix 24\nArbeitspreis: 28,45 ct/kWh brutto\nGrundpreis: 155,40 € pro Jahr brutto\n"
            . "Mindestlaufzeit: 24 Monate\nKündigungsfrist: 1 Monat zum Laufzeitende\nIhr monatlicher Abschlag: 82,00 €\n"
            . "Neukundenbonus: 60,00 €\nPreisgarantie bis 28.02.2028\nVertragsnummer: ST-55-1234\nZählernummer: TEST-STROM-9"];
    }

    public static function confirmationAnswer(): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => $f('strom', 'Vertragsbestätigung Strom'),
            'supplier' => $f('Musterenergie Nord AG', 'Musterenergie Nord AG'),
            'supply_start' => $f('01.03.2026', 'Ihr Lieferbeginn ist der 01.03.2026.'),
            'tariff_name' => $f('Ökostrom Fix 24', 'Tarif: Ökostrom Fix 24'),
            'working_price' => $f('28,45', 'Arbeitspreis: 28,45 ct/kWh brutto'),
            'base_price' => $f('155,40', 'Grundpreis: 155,40 € pro Jahr brutto'),
            'base_price_period' => $f('year', 'Grundpreis: 155,40 € pro Jahr brutto'),
            'min_term_months' => $f('24', 'Mindestlaufzeit: 24 Monate'),
            'notice_period' => $f('1 Monat zum Laufzeitende', 'Kündigungsfrist: 1 Monat zum Laufzeitende'),
            'advance' => $f('82,00', 'Ihr monatlicher Abschlag: 82,00 €'),
            'bonus' => $f('60,00', 'Neukundenbonus: 60,00 €'),
            'price_guarantee_until' => $f('28.02.2028', 'Preisgarantie bis 28.02.2028'),
            'contract_number' => $f('ST-55-1234', 'Vertragsnummer: ST-55-1234'),
            'meter_number' => $f('TEST-STROM-9', 'Zählernummer: TEST-STROM-9'),
            'market_location' => ['value' => null, 'quote' => null, 'page' => null],
        ];
    }

    /** @return list<string> */
    public static function priceChangePages(): array
    {
        return ["Stadtwerke Musterstadt GmbH\nPreisanpassung Erdgas zum 01.04.2026\nVertragskonto: VK-778899\n"
            . "Ihr Arbeitspreis steigt von 11,90 ct/kWh auf 12,80 ct/kWh.\nDer Grundpreis steigt von 12,50 € pro Monat auf 13,10 € pro Monat.\n"
            . "Grund: höhere Netzentgelte.\nSie haben ein Sonderkündigungsrecht bis zum 31.03.2026."];
    }

    public static function priceChangeAnswer(): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => $f('gas', 'Preisanpassung Erdgas zum 01.04.2026'),
            'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'valid_from' => $f('01.04.2026', 'Preisanpassung Erdgas zum 01.04.2026'),
            'working_price_old' => $f('11,90', 'Ihr Arbeitspreis steigt von 11,90 ct/kWh auf 12,80 ct/kWh.'),
            'working_price_new' => $f('12,80', 'Ihr Arbeitspreis steigt von 11,90 ct/kWh auf 12,80 ct/kWh.'),
            'base_price_old' => $f('12,50', 'Der Grundpreis steigt von 12,50 € pro Monat'),
            'base_price_new' => $f('13,10', 'auf 13,10 € pro Monat'),
            'base_price_period' => $f('month', 'auf 13,10 € pro Monat'),
            'reason' => $f('höhere Netzentgelte', 'Grund: höhere Netzentgelte.'),
            'special_termination_until' => $f('31.03.2026', 'Sonderkündigungsrecht bis zum 31.03.2026'),
            'contract_number' => $f('VK-778899', 'Vertragskonto: VK-778899'),
        ];
    }

    // ── v3.7.0 (Paket betrieb) — weitere Arten, Tage als TT.MM.JJJJ ──────

    /** @return list<string> */
    public static function advanceChangePages(string $from): array
    {
        return ["Stadtwerke Musterstadt GmbH\nAbschlagsänderung Erdgas\nVertragskonto: VK-778899\n"
            . "Ihr neuer Abschlag ab $from: 155,00 € monatlich"];
    }

    public static function advanceChangeAnswer(string $from): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => $f('gas', 'Abschlagsänderung Erdgas'),
            'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'advance_new' => $f('155,00', "Ihr neuer Abschlag ab $from: 155,00 €"),
            'valid_from' => $f($from, "Ihr neuer Abschlag ab $from"),
            'contract_number' => $f('VK-778899', 'Vertragskonto: VK-778899'),
        ];
    }

    /** @return list<string> */
    public static function cancellationPages(string $end): array
    {
        return ["Stadtwerke Musterstadt GmbH\nKündigungsbestätigung Erdgas\nVertragskonto: VK-778899\n"
            . "Wir bestätigen Ihre Kündigung. Lieferende ist der $end."];
    }

    public static function cancellationAnswer(string $end): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => $f('gas', 'Kündigungsbestätigung Erdgas'),
            'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'supply_end' => $f($end, "Lieferende ist der $end."),
            'cancel_kind' => $f('customer', 'Kündigungsbestätigung Erdgas'),
            'contract_number' => $f('VK-778899', 'Vertragskonto: VK-778899'),
        ];
    }

    /** @return list<string> */
    public static function readingRequestPages(string $by): array
    {
        return ["Netzbetreiber Musterstadt\nBitte Zählerstand mitteilen\nZählernummer: TEST-4711-01\n"
            . "Bitte teilen Sie uns Ihren Zählerstand bis zum $by mit."];
    }

    public static function readingRequestAnswer(string $by): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => ['value' => 'gas', 'quote' => null, 'page' => null],
            'supplier' => $f('Netzbetreiber Musterstadt', 'Netzbetreiber Musterstadt'),
            'requested_by' => $f($by, "Zählerstand bis zum $by mit."),
            'meter_number' => $f(self::METER, 'Zählernummer: TEST-4711-01'),
        ];
    }

    /** @return list<string> */
    public static function meterChangePages(): array
    {
        return ["Netzbetreiber Musterstadt\nZählerwechsel am 15.05.2026\nAusbau Zähler TEST-4711-01, Endstand 13.902,5 m³\n"
            . "Einbau Zähler TEST-4711-02, Anfangsstand 0,8 m³"];
    }

    public static function meterChangeAnswer(): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        return [
            'utility' => ['value' => 'gas', 'quote' => null, 'page' => null],
            'change_date' => $f('15.05.2026', 'Zählerwechsel am 15.05.2026'),
            'old_meter_number' => $f(self::METER, 'Ausbau Zähler TEST-4711-01'),
            'old_reading' => $f('13.902,5', 'Endstand 13.902,5 m³'),
            'new_meter_number' => $f('TEST-4711-02', 'Einbau Zähler TEST-4711-02'),
            'new_reading' => $f('0,8', 'Anfangsstand 0,8 m³'),
        ];
    }

    /** Ein Anbieter, der vorbereitete Antworten liefert und mitzählt. */
    public static function provider(string $id = 'local', array $types = [], array $fields = [], string $model = 'fake-1'): FakeInboxProvider
    {
        return new FakeInboxProvider($id, $types, $fields, $model);
    }

    /** Ein kleines, gültiges PDF (Inhalt je Kennung verschieden — sonst wäre es ein Doppel). */
    public static function pdf(string $tag = 'a'): string
    {
        return "%PDF-1.4\n% inbox test $tag\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
