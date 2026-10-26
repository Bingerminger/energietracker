<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

/**
 * v3.9.0 (F1034) — erfundene Wasser-Jahresrechnung, nacherzählt im Aufbau einer
 * echten (Spalten, Zeilenumbrüche, Sperrung, Codes wie dort): drei Sparten mit
 * Nettopreisen und eigenem Steuersatz (Trinkwasser 7 %, Schmutz- und
 * Niederschlagswasser 19 %), je Sparte Mengen-, Basis- und Bereitstellungspreis
 * mit einem Preiswechsel zum 01.10., Niederschlagswasser nach Fläche, Ablesecodes
 * „S“ (Schätzung) und „I“ (Internetablesung), Abschläge alle zwei Monate zu
 * genannten Terminen — und die Textebene führt die Beträge mit gesperrten
 * Ziffern („3 1 5,5 9 €“). Anbieter, Nummern, Beträge und Tage sind erfunden.
 *
 * Die Summen gehen auf (nachgerechnet): Trinkwasser 73,50 + 85,10 + 25,07 +
 * 50,14 + 26,93 + 54,85 = 315,59 netto, + 7 % = 337,68; Schmutzwasser 63,00 +
 * 72,15 + 2 × 20,05 + 2 × 21,94 = 219,13 netto, + 19 % = 260,76;
 * Niederschlagswasser 100,27 + 109,70 = 209,97 netto, + 19 % = 249,86; zusammen
 * 848,30 €, abzüglich 800,00 € Abschläge = 48,30 € Nachzahlung. Grundpreise je
 * Jahr anteilig nach Tagen (183 bzw. 182 von 365).
 */
final class WaterBillFixture
{
    public const SUPPLIER = 'Stadtwerke Musterstadt GmbH';
    public const METER = '12345678901';
    public const ACCOUNT = '4000123456';

    /**
     * v3.9.0 — dieselben Zahlen, wie der Browser sie seit dem Lesen mit Lage
     * liefert (lib/pdf.js joinTextItems: Stücke nach x, Leerzeichen nach dem
     * Abstand): ohne Sperrung, „€/m³“ ohne Lücken. Von Hand aufgeschrieben, nicht
     * aus der Belegprüfung abgeleitet.
     */
    private const BROWSER = [
        '3 1 5,5 9 €' => '315,59 €', '2 2,0 9 €' => '22,09 €', '3 3 7,6 8 €' => '337,68 €', '2 1 9,1 3 €' => '219,13 €',
        '4 1,6 3 €' => '41,63 €', '2 6 0,7 6 €' => '260,76 €', '2 0 9,9 7 €' => '209,97 €', '3 9,8 9 €' => '39,89 €',
        '2 4 9,8 6 €' => '249,86 €', '8 4 8,3 0 €' => '848,30 €', '-8 0 0 , 0 0 €' => '-800,00 €', '4 8,3 0 €' => '48,30 €',
        '6 0,7 5 €' => '60,75 €', '4,2 5 €' => '4,25 €', '6 5,0 0 €' => '65,00 €', '4 2,0 2 €' => '42,02 €', '7,9 8 €' => '7,98 €',
        '5 0,0 0 €' => '50,00 €', '1 6 5,0 0 €' => '165,00 €',
        '3 5 m³' => '35 m³', '3 7 m³' => '37 m³', '8 0 m³' => '80 m³', '2 , 1 0 € / m³' => '2,10 €/m³', '2 , 3 0 € / m³' => '2,30 €/m³',
        '1 , 8 0 € / m³' => '1,80 €/m³', '1 , 9 5 € / m³' => '1,95 €/m³', '7 3,5 0 €' => '73,50 €', '8 5,1 0 €' => '85,10 €',
        '1 8 3 Tage' => '183 Tage', '1 8 2 Tage' => '182 Tage',
        '5 0 , 0 0 € / Jahr' => '50,00 €/Jahr', '1 0 0 , 0 0 € / Jahr' => '100,00 €/Jahr', '5 4 , 0 0 € / Jahr' => '54,00 €/Jahr',
        '1 1 0 , 0 0 € / Jahr' => '110,00 €/Jahr', '4 0 , 0 0 € / Jahr' => '40,00 €/Jahr', '4 4 , 0 0 € / Jahr' => '44,00 €/Jahr',
        '2 5,0 7 €' => '25,07 €', '5 0,1 4 €' => '50,14 €', '2 6,9 3 €' => '26,93 €', '5 4,8 5 €' => '54,85 €',
        '6 3,0 0 €' => '63,00 €', '7 2,1 5 €' => '72,15 €', '2 0,0 5 €' => '20,05 €', '2 1,9 4 €' => '21,94 €', '1 9 %' => '19 %',
        '2 0 0 , 0 0 m²' => '200,00 m²', '1 0 0,2 7 €' => '100,27 €', '1 0 9,7 0 €' => '109,70 €',
        '2 1 , 0 0 €' => '21,00 €', '4 0 , 0 0 €' => '40,00 €', '3 8 , 0 0 €' => '38,00 €',
    ];

    /**
     * Textebene je Seite. `$layout`: `true` Spalten mit mehreren Leerzeichen wie
     * `pdftotext -layout`; `false` ein Leerzeichen zwischen den Textstücken, wie
     * der Browser bis v3.8 las (Sperrung bleibt); `'browser'` wie der Browser
     * seit v3.9.0 mit Lage liest (ein Leerzeichen, Ziffern ohne Sperrung).
     *
     * @return list<string>
     */
    public static function pages(bool|string $layout = true): array
    {
        $p = [
            <<<'TXT'
Stadtwerke Musterstadt GmbH   .   Postfach 1000   .   12345 Musterstadt
                                                         Datum:                       20.04.2026
                                                         Vertragskontonummer          4000123456

Rechnung Nr. 9100000001

für die Zeit vom 01.04.2025 bis 31.03.2026 berechnen wir Ihnen für:
Leistungen                               Nettoentgelt          Umsatzsteuer                 Bruttoentgelt
Trinkwasser                                3 1 5,5 9 €         7%          2 2,0 9 €          3 3 7,6 8 €
Schmutzwasser                              2 1 9,1 3 €        19 %         4 1,6 3 €          2 6 0,7 6 €
Niederschlagswasser                        2 0 9,9 7 €        19 %         3 9,8 9 €          2 4 9,8 6 €
Rechnungsbetrag                                                                               8 4 8,3 0 €
abzügl. geleistete Abschläge                                                                 -8 0 0 , 0 0 €
Rechnungsforderung fällig am 05.05.2026                                                         4 8,3 0 €

Nach den genannten Verbrauchswerten und den aktuellen Preisen ergeben sich für Sie folgende weitere
Abschlagsbeträge, die wir zu den angegebenen Terminen von Ihrem Konto abbuchen.
                                         Nettoentgelt          Umsatzsteuer                 Bruttoentgelt
Trinkwasser                                  6 0,7 5 €         7%            4,2 5 €            6 5,0 0 €
Schmutzwasser                                4 2,0 2 €        19 %           7,9 8 €            5 0,0 0 €
Niederschlagswasser                          4 2,0 2 €        19 %           7,9 8 €            5 0,0 0 €
Abschlagsbetrag                                                                               1 6 5,0 0 €
Die Abschlagsbeträge sind zu folgenden Terminen fällig:
                 15.05.2026, 15.07.2026, 15.09.2026, 15.11.2026, 15.01.2027
TXT,
            <<<'TXT'
Verbrauchs- und Betragsermittlung Trinkwasser
Zählernummer                     Zeitraum                    Zählerstand in m³        Faktor     Verbrauch in m³
                           von           bis                 alt          neu
12345678901             01.04.2025 30.09.2025               1200         1235 S                        35
12345678901             01.10.2025 31.03.2026               1235         1272 I                        37
                                                                  Gesamtverbrauch:                     72
Preisart                         Zeitraum                    Menge             Preis              Nettoentgelt     Ust.
Wasserverbrauch         01.04.2025 - 30.09.2025              3 5 m³         2 , 1 0 € / m³          7 3,5 0 €   7%
Wasserverbrauch         01.10.2025 - 31.03.2026              3 7 m³         2 , 3 0 € / m³          8 5,1 0 €   7%
Basispreis              01.04.2025 - 30.09.2025          1 8 3 Tage        5 0 , 0 0 € / Jahr        2 5,0 7 €   7%
QN 2,5\Q3/4,0           01.04.2025 - 30.09.2025          1 8 3 Tage      1 0 0 , 0 0 € / Jahr        5 0,1 4 €   7%
Bereitstellungsprs.
Basispreis              01.10.2025 - 31.03.2026          1 8 2 Tage        5 4 , 0 0 € / Jahr        2 6,9 3 €   7%
QN 2,5\Q3/4,0           01.10.2025 - 31.03.2026          1 8 2 Tage      1 1 0 , 0 0 € / Jahr        5 4,8 5 €   7%
Bereitstellungsprs.
Nettoentgelt                                                                                     3 1 5,5 9 €
Umsatzsteuer                                                                                       2 2,0 9 €   7%
Bruttoentgelt                                                                                    3 3 7,6 8 €
Vorperiodenverbrauch    01.04.2024 - 31.03.2025              8 0 m³
S=Schätzung       I=Internetablesung
TXT,
            <<<'TXT'
Verbrauchs- und Betragsermittlung Schmutzwasser
Zählernummer                     Zeitraum                    Zählerstand in m³        Faktor     Verbrauch in m³
                           von           bis                 alt          neu
12345678901             01.04.2025 30.09.2025               1200         1235 S                        35
12345678901             01.10.2025 31.03.2026               1235         1272 I                        37
Preisart                         Zeitraum                    Menge             Preis              Nettoentgelt     Ust.
Abwassermenge           01.04.2025 - 30.09.2025              3 5 m³         1 , 8 0 € / m³          6 3,0 0 € 1 9 %
Abwassermenge           01.10.2025 - 31.03.2026              3 7 m³         1 , 9 5 € / m³          7 2,1 5 € 1 9 %
Basispreis              01.04.2025 - 30.09.2025          1 8 3 Tage        4 0 , 0 0 € / Jahr        2 0,0 5 € 1 9 %
QN 2,5\Q3/4,0           01.04.2025 - 30.09.2025          1 8 3 Tage        4 0 , 0 0 € / Jahr        2 0,0 5 € 1 9 %
Bereitstellungsprs.
Basispreis              01.10.2025 - 31.03.2026          1 8 2 Tage        4 4 , 0 0 € / Jahr        2 1,9 4 € 1 9 %
QN 2,5\Q3/4,0           01.10.2025 - 31.03.2026          1 8 2 Tage        4 4 , 0 0 € / Jahr        2 1,9 4 € 1 9 %
Bereitstellungsprs.
Nettoentgelt                                                                                     2 1 9,1 3 €
Umsatzsteuer                                                                                       4 1,6 3 € 1 9 %
Bruttoentgelt                                                                                    2 6 0,7 6 €
S=Schätzung       I=Internetablesung
TXT,
            <<<'TXT'
Verbrauchs- und Betragsermittlung Niederschlagswasser
Preisart                         Zeitraum            abr. Fläche          Preis                              Nettoentgelt     Ust.
Niederschlagswasserpreis 01.04.2025 - 30.09.2025    2 0 0 , 0 0 m²   1,00 €/m² Jahr (183 Tage)              1 0 0,2 7 € 1 9 %
Niederschlagswasserpreis 01.10.2025 - 31.03.2026    2 0 0 , 0 0 m²   1,10 €/m² Jahr (182 Tage)              1 0 9,7 0 € 1 9 %
Bankverbindung: Musterbank Musterstadt · Amtsgericht Musterstadt HRB 0000 · Geschäftsführung: N. N.
TXT,
            <<<'TXT'
Nettoentgelt                                                                                     2 0 9,9 7 €
Umsatzsteuer                                                                                       3 9,8 9 € 1 9 %
Bruttoentgelt                                                                                    2 4 9,8 6 €

Umsatzsteuernachweis
Die geleisteten Abschläge enthielten:
Umsatzsteuer Trinkwasser                          2 1 , 0 0 € (7%)
Umsatzsteuer Schmutzwasser                        4 0 , 0 0 € (19%)
Umsatzsteuer Niederschlagswasser                  3 8 , 0 0 € (19%)
Hinweise zu Ihrer Rechnung: Der Basispreis deckt die Kosten der Bereitstellung, der Bereitstellungspreis richtet sich nach der Größe des Zählers.
TXT,
        ];
        if ($layout === true) return $p;
        // wie pdf.js: Spalten mit einem Leerzeichen getrennt; die Sperrung (ein Leerzeichen) bleibt
        $single = array_map(fn(string $s) => (string)preg_replace('/ {2,}/', ' ', $s), $p);
        return $layout === 'browser' ? array_map(fn(string $s) => strtr($s, self::BROWSER), $single) : $single;
    }

    /**
     * Antwort eines Modells auf diese Rechnung (Werte wie gedruckt, Zitate so,
     * wie das Modell die zusammengefasste Textebene sieht: ein Leerzeichen
     * zwischen den Spalten, die Sperrung bleibt).
     *
     * `$variant` `'browser'`: die Zitate aus der Textebene, wie der Browser sie
     * seit v3.9.0 liefert (pages('browser')).
     *
     * @return array<string,mixed>
     */
    public static function answer(string $variant = ''): array
    {
        if ($variant === 'browser') {
            $fix = function (array $node) use (&$fix): array {
                foreach ($node as $k => $v) {
                    if ($k === 'quote' && is_string($v)) $node[$k] = strtr($v, self::BROWSER);
                    elseif (is_array($v)) $node[$k] = $fix($v);
                }
                return $node;
            };
            return $fix(self::answer());
        }
        $f = fn($v, ?string $q, ?int $p = 1) => ['value' => $v, 'quote' => $q, 'page' => $q === null ? null : $p];
        $row1 = '12345678901 01.04.2025 30.09.2025 1200 1235 S 35';
        $row2 = '12345678901 01.10.2025 31.03.2026 1235 1272 I 37';
        $wp = fn(string $section, string $label, string $from, string $to, string $price, string $unit, string $vat, string $quote, int $page, ?string $area = null) => [
            'section' => $section, 'label' => $label, 'from' => $from, 'to' => $to, 'price' => $price, 'unit' => $unit,
            'area_m2' => $area, 'basis' => 'net', 'vat_pct' => $vat, 'quote' => $quote, 'page' => $page];
        return [
            'utility' => $f('wasser', 'Trinkwasser 3 1 5,5 9 € 7% 2 2,0 9 € 3 3 7,6 8 €'),
            'supplier' => $f(self::SUPPLIER, 'Stadtwerke Musterstadt GmbH'),
            'kind' => $f('annual', 'Rechnung Nr. 9100000001'),
            'period_from' => $f('01.04.2025', 'für die Zeit vom 01.04.2025 bis 31.03.2026'),
            'period_to' => $f('31.03.2026', 'für die Zeit vom 01.04.2025 bis 31.03.2026'),
            'consumption' => $f('72', 'Gesamtverbrauch: 72', 2),
            'reading_new' => $f('1272', $row2, 2),
            'reading_new_date' => $f('31.03.2026', $row2, 2),
            'reading_new_estimated' => $f(false, $row2, 2),
            'reading_old' => $f('1200', $row1, 2),
            'reading_old_date' => $f('01.04.2025', $row1, 2),
            'reading_old_estimated' => $f(true, $row1, 2),
            'total_amount' => $f('848,30', 'Rechnungsbetrag 8 4 8,3 0 €'),
            'advances_paid' => ['value' => [['amount' => '800,00', 'date' => null, 'quote' => 'abzügl. geleistete Abschläge -8 0 0 , 0 0 €', 'page' => 1]],
                'quote' => null, 'page' => null],
            'advances_total' => $f('800,00', 'abzügl. geleistete Abschläge -8 0 0 , 0 0 €'),
            'result_kind' => $f('due', 'Rechnungsforderung fällig am 05.05.2026 4 8,3 0 €'),
            'result_amount' => $f('48,30', 'Rechnungsforderung fällig am 05.05.2026 4 8,3 0 €'),
            'advance_new' => $f('165,00', 'Abschlagsbetrag 1 6 5,0 0 €'),
            'advance_new_from' => $f('15.05.2026', '15.05.2026, 15.07.2026, 15.09.2026, 15.11.2026, 15.01.2027'),
            'advance_new_dates' => ['value' => array_map(fn($d) => ['date' => $d, 'quote' => '15.05.2026, 15.07.2026, 15.09.2026, 15.11.2026, 15.01.2027', 'page' => 1],
                ['15.05.2026', '15.07.2026', '15.09.2026', '15.11.2026', '15.01.2027']), 'quote' => null, 'page' => null],
            'advance_new_every' => $f(null, null),
            'meter_number' => $f(self::METER, $row1, 2),
            'meter_readings' => ['value' => [
                ['meter_number' => self::METER, 'reading_old' => '1200', 'reading_old_date' => '01.04.2025', 'reading_new' => '1235',
                 'reading_new_date' => '30.09.2025', 'estimated' => true, 'quote' => $row1, 'page' => 2],
                ['meter_number' => self::METER, 'reading_old' => '1235', 'reading_old_date' => '01.10.2025', 'reading_new' => '1272',
                 'reading_new_date' => '31.03.2026', 'estimated' => false, 'quote' => $row2, 'page' => 2],
            ], 'quote' => null, 'page' => null],
            'contract_number' => $f(self::ACCOUNT, 'Vertragskontonummer 4000123456'),
            'customer_number' => $f(null, null),
            'document_date' => $f('20.04.2026', 'Datum: 20.04.2026'),
            'document_title' => $f('Rechnung', 'Rechnung Nr. 9100000001'),
            'water_prices' => ['value' => [
                $wp('trinkwasser', 'Wasserverbrauch', '01.04.2025', '30.09.2025', '2,10', 'm3', '7', 'Wasserverbrauch 01.04.2025 - 30.09.2025 3 5 m³ 2 , 1 0 € / m³ 7 3,5 0 € 7%', 2),
                $wp('trinkwasser', 'Wasserverbrauch', '01.10.2025', '31.03.2026', '2,30', 'm3', '7', 'Wasserverbrauch 01.10.2025 - 31.03.2026 3 7 m³ 2 , 3 0 € / m³ 8 5,1 0 € 7%', 2),
                $wp('trinkwasser', 'Basispreis', '01.04.2025', '30.09.2025', '50,00', 'year', '7', 'Basispreis 01.04.2025 - 30.09.2025 1 8 3 Tage 5 0 , 0 0 € / Jahr 2 5,0 7 € 7%', 2),
                $wp('trinkwasser', 'Bereitstellungspreis', '01.04.2025', '30.09.2025', '100,00', 'year', '7', 'QN 2,5\Q3/4,0 01.04.2025 - 30.09.2025 1 8 3 Tage 1 0 0 , 0 0 € / Jahr 5 0,1 4 € 7% Bereitstellungsprs.', 2),
                $wp('trinkwasser', 'Basispreis', '01.10.2025', '31.03.2026', '54,00', 'year', '7', 'Basispreis 01.10.2025 - 31.03.2026 1 8 2 Tage 5 4 , 0 0 € / Jahr 2 6,9 3 € 7%', 2),
                $wp('trinkwasser', 'Bereitstellungspreis', '01.10.2025', '31.03.2026', '110,00', 'year', '7', 'QN 2,5\Q3/4,0 01.10.2025 - 31.03.2026 1 8 2 Tage 1 1 0 , 0 0 € / Jahr 5 4,8 5 € 7% Bereitstellungsprs.', 2),
                $wp('schmutzwasser', 'Abwassermenge', '01.04.2025', '30.09.2025', '1,80', 'm3', '19', 'Abwassermenge 01.04.2025 - 30.09.2025 3 5 m³ 1 , 8 0 € / m³ 6 3,0 0 € 1 9 %', 3),
                $wp('schmutzwasser', 'Abwassermenge', '01.10.2025', '31.03.2026', '1,95', 'm3', '19', 'Abwassermenge 01.10.2025 - 31.03.2026 3 7 m³ 1 , 9 5 € / m³ 7 2,1 5 € 1 9 %', 3),
                $wp('schmutzwasser', 'Basispreis', '01.04.2025', '30.09.2025', '40,00', 'year', '19', 'Basispreis 01.04.2025 - 30.09.2025 1 8 3 Tage 4 0 , 0 0 € / Jahr 2 0,0 5 € 1 9 %', 3),
                $wp('schmutzwasser', 'Bereitstellungspreis', '01.04.2025', '30.09.2025', '40,00', 'year', '19', 'QN 2,5\Q3/4,0 01.04.2025 - 30.09.2025 1 8 3 Tage 4 0 , 0 0 € / Jahr 2 0,0 5 € 1 9 % Bereitstellungsprs.', 3),
                $wp('schmutzwasser', 'Basispreis', '01.10.2025', '31.03.2026', '44,00', 'year', '19', 'Basispreis 01.10.2025 - 31.03.2026 1 8 2 Tage 4 4 , 0 0 € / Jahr 2 1,9 4 € 1 9 %', 3),
                $wp('schmutzwasser', 'Bereitstellungspreis', '01.10.2025', '31.03.2026', '44,00', 'year', '19', 'QN 2,5\Q3/4,0 01.10.2025 - 31.03.2026 1 8 2 Tage 4 4 , 0 0 € / Jahr 2 1,9 4 € 1 9 % Bereitstellungsprs.', 3),
                $wp('niederschlagswasser', 'Niederschlagswasserpreis', '01.04.2025', '30.09.2025', '1,00', 'm2_year', '19', 'Niederschlagswasserpreis 01.04.2025 - 30.09.2025 2 0 0 , 0 0 m² 1,00 €/m² Jahr (183 Tage) 1 0 0,2 7 € 1 9 %', 4, '200,00'),
                $wp('niederschlagswasser', 'Niederschlagswasserpreis', '01.10.2025', '31.03.2026', '1,10', 'm2_year', '19', 'Niederschlagswasserpreis 01.10.2025 - 31.03.2026 2 0 0 , 0 0 m² 1,10 €/m² Jahr (182 Tage) 1 0 9,7 0 € 1 9 %', 4, '200,00'),
            ], 'quote' => null, 'page' => null],
        ];
    }

    /**
     * Brutto je Sparte und Abschnitt, mit der Hand gerechnet (netto × (1 + Satz)):
     * Arbeitspreise in ct/m³, Grundpreise je Monat (Basis- + Bereitstellungspreis
     * je Jahr ÷ 12), Niederschlagswasser je m² und Jahr.
     */
    public const GROSS = [
        'trinkwasser' => ['working' => ['2025-04-01' => 224.7, '2025-10-01' => 246.1],                // 2,10 bzw. 2,30 € × 1,07
                          'base' => ['2025-04-01' => 13.375, '2025-10-01' => 14.6233]],             // 150 bzw. 164 € × 1,07 ÷ 12
        'schmutzwasser' => ['working' => ['2025-04-01' => 214.2, '2025-10-01' => 232.05],             // 1,80 bzw. 1,95 € × 1,19
                            'base' => ['2025-04-01' => 7.9333, '2025-10-01' => 8.7267]],             // 80 bzw. 88 € × 1,19 ÷ 12
        'niederschlagswasser' => ['rate' => ['2025-04-01' => 1.19, '2025-10-01' => 1.309]],          // 1,00 bzw. 1,10 € × 1,19
    ];
}
