<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

/**
 * v3.7.0 (Posteingang) — Dokumentarten und ihre Felder, eine Quelle für
 * Anbieter (Prompt), Belegprüfung, Vorschau und Oberfläche.
 *
 * Arten und Kernfelder stammen aus der Machbarkeitsprobe (61 Dokumente, Gas und
 * Strom): erst die Art erkennen, dann genau die Felder dieser Art nach festem
 * Schema. Drei Gruppen:
 *   - ausgelesen: Rechnung, Vertragsbestätigung, Preisänderung, Kündigung,
 *     Lieferbeginn;
 *   - ausgelesen mit Pflichtprüfung (jedes Feld markiert): Zählerwechsel,
 *     Zählerstandsanforderung, Abschlagsänderung — in der Probe verwechselte
 *     das Modell dort Zählernummern;
 *   - nur erkennen und ablegen: Mahnung, Widerruf, Sonstiges.
 *
 * Ein Feld: `kind` (date | number | money | price_ct | text | enum | list | bool),
 * `desc` (Beschreibung für das Modell, englisch mit den Begriffen der
 * Dokumente), `core` (Kernfeld: leer = prüfen), `options` + `hints` (enum:
 * erlaubte Werte und Stichwörter, die im Zitat stehen müssen), `review`
 * (immer prüfen, z. B. Preise aus Rechnungen), `items` (list: Felder je Eintrag).
 *
 * VERSION gehört zum Schlüssel des Zwischenspeichers (Prüfsumme + Modell +
 * Schema-Version): Ändert sich ein Feld, wird neu ausgelesen.
 *
 * v3.7.0 (treffsicher, Nachbesserung 2) — VERSION 2: Netto und Steuersatz je
 * Preis der Rechnung (`basis`, `vat_pct` je Arbeitspreis; `base_price_basis`,
 * `base_price_vat_pct`), der Steuersatz der Bestätigung und der Preisänderung
 * (`vat_pct`). Hilfsfelder (`aux`): markieren nie; ob der Satz gelesen ist,
 * prüft Evidence::taxOf() am Zitat. Schon ausgelesene Dokumente behalten ihre
 * Auslesung (die Marken rechnet Evidence::refine aus den Zitaten neu); erst ein
 * erneutes Auslesen fragt das Modell nach den neuen Feldern.
 *
 * v3.7.0 (treffsicher, Nachbesserung 4) — VERSION 3: `document_date` und
 * `document_title` für jede ausgelesene Art (Briefdatum und Überschrift: Reihenfolge
 * der Post, Rechnungskorrekturen; Namen mit dem Paket „erstbefuellung“
 * abgestimmt), `meter_readings` an der Rechnung (Stände je Zähler, beim Tausch
 * beide). Die Einzelfelder (`meter_number`, `reading_old` …) bleiben und werden
 * weiter gefüllt. Dazu die Ablese-Art jedes Stands der Rechnung
 * (`reading_new_estimated`, `reading_old_estimated`, `estimated` je Eintrag).
 * Der Zwischenspeicher der Version 2 trifft nicht mehr.
 *
 * v3.7.0 (treffsicher, Nachbesserung 5) — VERSION 4: Beschreibungen für das
 * Modell geändert — Zählernummer ohne Fußnotenzeichen (`format: meter`), Stände
 * je Zähler je Zeitraum mit der Nummer auch aus einer Kopfzeile, „hochgerechnet“,
 * Fußnoten und Codegruppen bei der Ablese-Art. Felder unverändert; neu sind nur
 * Angaben der Prüfung (`between`, `merged`, `estimated_ends`, `number_from`,
 * `found_parts`, `quote_found`), die das Modell nicht liefert.
 *
 * v3.9.0 (F1032, GitHub #40) — VERSION 5: je Eintrag von `meter_readings` das
 * Zählwerk (`obis`, `register`; Hilfsfelder). Steht dieselbe Zählernummer mit
 * mehreren Zählwerken auf der Rechnung (HT/NT, Bezug/Einspeisung), ordnet
 * Proposals jeden Stand dem Zähler mit diesem Zählwerk zu.
 */
final class InboxSchema
{
    public const VERSION = 5;

    public const TYPES = [
        'invoice', 'contract_confirmation', 'price_change', 'cancellation', 'supply_start',
        'meter_change', 'reading_request', 'advance_change',
        'dunning', 'withdrawal', 'other',
    ];

    /** Nur erkennen und ablegen (kein Auslesen der Felder). */
    public const RECOGNIZE_ONLY = ['dunning', 'withdrawal', 'other'];

    /** Jedes Feld dieser Arten gilt als Vorschlag mit Pflichtprüfung. */
    public const REVIEW_ALL = ['meter_change', 'reading_request', 'advance_change'];

    /**
     * v3.7.0 (treffsicher) — Kernfelder je Vorschlag statt je Dokumentart. Ein
     * leeres Feld ist zu prüfen (Grund `empty`), wenn ein Vorschlag es braucht;
     * sonst fehlt es nur im Dokument (`missing`, keine Marke) und bleibt ein
     * offener Punkt am Vorschlag. Im Nutzentest waren 28 von 32 leeren
     * Kernfeldern wirklich nicht im Dokument (etwa der neue Abschlag einer
     * Schlussrechnung).
     *
     *   required  braucht jedes Dokument der Art (ohne entsteht kein Vorschlag)
     *   with      steht das Feld da, braucht sein Vorschlag auch diese
     *             (Zählerstand und Datum, Ergebnis und Art, Grundpreis und Periode)
     *   any       mindestens eines davon (die Rechnung ohne Verbrauch und
     *             Betrag hat nichts zu vergleichen)
     *
     * `core` an den Feldern bleibt die Liste aus der Probe (Anzeige, Reihenfolge).
     */
    public const NEEDS = [
        'invoice' => [
            'required' => ['period_from', 'period_to'],
            'with' => ['reading_new' => ['reading_new_date'], 'reading_new_date' => ['reading_new'],
                       'reading_old' => ['reading_old_date'], 'reading_old_date' => ['reading_old'],
                       'result_amount' => ['result_kind'], 'result_kind' => ['result_amount'],
                       'advance_new_from' => ['advance_new'], 'base_price' => ['base_price_period']],
            'any' => [['consumption', 'total_amount']],
        ],
        'contract_confirmation' => ['required' => ['supply_start'], 'with' => ['base_price' => ['base_price_period']]],
        'price_change' => ['required' => ['valid_from'], 'with' => ['base_price_new' => ['base_price_period']],
                           'any' => [['working_price_new', 'base_price_new']]],
        'cancellation' => ['required' => ['supply_end']],
        'supply_start' => ['required' => ['supply_start']],
        'meter_change' => ['required' => ['change_date', 'old_meter_number', 'old_reading', 'new_meter_number', 'new_reading']],
        'reading_request' => ['required' => ['requested_by', 'meter_number']],
        'advance_change' => ['required' => ['advance_new', 'valid_from']],
    ];

    /**
     * Welche Felder braucht ein Vorschlag, wenn diese Felder dastehen?
     *
     * @param array<string,bool> $present Feld → steht im Dokument
     * @return array<string,true>
     */
    public static function needed(string $type, array $present): array
    {
        $n = self::NEEDS[$type] ?? [];
        $out = [];
        foreach ($n['required'] ?? [] as $f) $out[$f] = true;
        foreach ($n['with'] ?? [] as $f => $deps) {
            if (!empty($present[$f])) foreach ($deps as $d) $out[$d] = true;
        }
        foreach ($n['any'] ?? [] as $group) {
            if (array_filter($group, fn($g) => !empty($present[$g])) === []) foreach ($group as $g) $out[$g] = true;
        }
        // Rechnung: Nennt sie Betrag und Abschläge, folgt die Art des Ergebnisses daraus
        if ($type === 'invoice' && !empty($present['total_amount']) && (!empty($present['advances_total']) || !empty($present['advances_paid']))
            && empty($present['result_kind'])) unset($out['result_kind']);
        return $out;
    }

    /** Verbrauchsarten, die das Modell nennen darf (Versorgerpost); Schlüssel wie Config\Utilities. */
    public const UTILITIES = ['gas', 'strom', 'wasser', 'fernwaerme'];

    /** Beschreibung der Arten für die Erkennung (Prompt). */
    public const TYPE_DESC = [
        'invoice'               => 'annual or final bill for a billing period (Jahresabrechnung, Jahresrechnung, Verbrauchsabrechnung, Schlussrechnung, Endabrechnung)',
        'contract_confirmation' => 'confirmation of a new supply contract or order (Vertragsbestätigung, Auftragsbestätigung, Willkommen als Kunde)',
        'price_change'          => 'notice of new prices from a date (Preisänderung, Preisanpassung, neue Preise ab)',
        'cancellation'          => 'termination of the contract or its confirmation (Kündigung, Kündigungsbestätigung, Lieferende)',
        'supply_start'          => 'confirmation that supply starts on a date (Lieferbeginn, Belieferung ab, Anmeldung bestätigt)',
        'meter_change'          => 'the meter was exchanged (Zählerwechsel, Zählertausch, neuer Zähler)',
        'reading_request'       => 'request to report a meter reading (Zählerstand mitteilen, Ablesung, Selbstablesung)',
        'advance_change'        => 'new monthly advance payment without a bill (Abschlagsänderung, neuer Abschlag, Abschlagsplan)',
        'dunning'               => 'payment reminder or dunning letter (Mahnung, Zahlungserinnerung)',
        'withdrawal'            => 'withdrawal from a contract or its confirmation (Widerruf, Widerrufsbestätigung)',
        'other'                 => 'anything else (newsletter, information, advertising)',
    ];

    private const UTILITY_FIELD = ['kind' => 'enum', 'options' => self::UTILITIES,
        'hints' => ['gas' => ['gas'], 'strom' => ['strom', 'elektri'], 'wasser' => ['wasser'], 'fernwaerme' => ['wärme', 'waerme']],
        'desc' => 'energy carrier of the document: gas (Erdgas), strom (electricity), wasser (water), fernwaerme (district heating)'];
    private const SUPPLIER = ['kind' => 'text', 'desc' => 'name of the supplier company (Versorger, Lieferant)'];
    /** `format: meter` — v3.7.0 (treffsicher, Nachbesserung 5): ein Sternchen im Wert oder Zitat markiert. */
    private const METER_NUMBER = ['kind' => 'text', 'format' => 'meter', 'desc' => 'meter number (Zählernummer), as printed, without footnote marks'];
    /** `format: malo` — v3.7.0 (treffsicher, Nachbesserung 3): Evidence prüft Länge und Prüfziffer. */
    private const MALO = ['kind' => 'text', 'format' => 'malo', 'desc' => 'market location ID, 11 digits (Marktlokation, MaLo-ID)'];
    private const CONTRACT_NUMBER = ['kind' => 'text', 'desc' => 'contract number (Vertragsnummer, Vertragskonto)'];
    private const CUSTOMER_NUMBER = ['kind' => 'text', 'desc' => 'customer number (Kundennummer)'];
    private const BASE_PERIOD = ['kind' => 'enum', 'options' => ['month', 'year'],
        'hints' => ['month' => ['monat', 'mtl'], 'year' => ['jahr', 'jährl', '/a', 'p.a']],
        'desc' => 'period of the standing charge: month or year (Grundpreis pro Monat / pro Jahr)'];

    /**
     * v3.7.0 (treffsicher, Nachbesserung 2) — netto oder brutto und Steuersatz eines
     * Preises, wie gedruckt (Hilfsfelder: keine Marke).
     */
    private const PRICE_BASIS = ['kind' => 'enum', 'options' => ['net', 'gross'], 'aux' => true,
        'desc' => 'net if the price is printed without VAT (netto, zzgl. USt), gross if it includes VAT (brutto, inkl. USt); null if the document does not say'];
    private const VAT_PCT = ['kind' => 'number', 'aux' => true,
        'desc' => 'VAT rate in % that applies to the price as printed (USt, MwSt), e.g. 19; null if not printed. Put the rate into the quote as well, joining distant parts with …'];

    /**
     * v3.7.0 (treffsicher, Nachbesserung 4) — Datum und Titel jedes ausgelesenen
     * Dokuments. Kein Vorschlag braucht sie (NEEDS): Fehlen sie, ist das keine Marke.
     */
    private const DOCUMENT = [
        // `helper`: Hilfsangabe — Nachbesserung 5: von der Pflichtprüfung (REVIEW_ALL) ausgenommen, außer ein Vorschlag braucht sie
        'document_date'  => ['kind' => 'date', 'helper' => true, 'desc' => 'date of the letter as printed, the issue date (Datum, Rechnungsdatum, Briefdatum, Ausstellungsdatum), not a period or due date'],
        'document_title' => ['kind' => 'text', 'helper' => true, 'desc' => 'title or heading of the document as printed, short, e.g. "Jahresabrechnung", "Rechnungskorrektur", "Vertragsbestätigung"; it may stand only in the page header'],
    ];

    /**
     * v3.7.0 (treffsicher, Nachbesserung 4) — Ablese-Art eines Zählerstands der
     * Rechnung (Hilfsfeld, keine Marke). Ob er geschätzt ist, entscheidet
     * Evidence::readEstimates am Zitat: Wort oder ein Code, den das Dokument erklärt.
     */
    private const ESTIMATED = ['kind' => 'bool', 'aux' => true,
        'desc' => 'true if the bill marks this reading as estimated or calculated (geschätzt, hochgerechnet, rechnerisch ermittelt, Schätzung, or a footnote mark or reading code the bill explains as an estimate, e.g. "5) hochgerechnet", "S = geschätzt", a code in a group like 10/12/26 or in a column Ableseart); false if it is marked as read; put the word, mark or code into the quote'];

    /**
     * v3.7.0 (treffsicher, Nachbesserung 4) — Stände je Zähler an der Rechnung.
     * Nachbesserung 5: je Ablesezeitraum ein Eintrag ist erlaubt — Evidence fasst
     * Einträge derselben Nummer zusammen; die Nummer darf in der Kopfzeile stehen.
     */
    private const METER_READINGS = ['kind' => 'list',
        'desc' => 'one item per meter and reading period on the bill with its readings (Zählerstände je Zähler); the meter number may stand in a heading line above its readings; after a meter exchange both meters: the removed one with its final reading, the new one with its initial reading',
        'items' => ['meter_number' => ['kind' => 'text', 'format' => 'meter', 'desc' => 'meter number (Zählernummer), as printed, without footnote marks'],
                    'reading_old' => ['kind' => 'number', 'desc' => 'reading at the start of the period or at installation (Anfangsstand, Einbaustand)'],
                    'reading_old_date' => ['kind' => 'date', 'desc' => 'date of that reading'],
                    'reading_new' => ['kind' => 'number', 'desc' => 'reading at the end of the period or at removal (Endstand, Ausbaustand)'],
                    'reading_new_date' => ['kind' => 'date', 'desc' => 'date of that reading'],
                    'estimated' => self::ESTIMATED,
                    // v3.9.0 (F1032, #40) — Zählwerk: eine Nummer, mehrere Stände (HT/NT, Bezug/Einspeisung)
                    'obis' => ['kind' => 'text', 'aux' => true, 'desc' => 'register code (OBIS-Kennzahl) of this reading if printed, e.g. 1.8.0, 1.8.1, 1.8.2, 2.8.0 or 1-0:1.8.1; empty if none is printed'],
                    'register' => ['kind' => 'text', 'aux' => true, 'desc' => 'register label of this reading if the meter has several (Zählwerk, Tarifzone), as printed, e.g. HT, NT, Hochtarif, Niedertarif, Bezug, Einspeisung; empty if the meter has only one']]];

    /**
     * Felder einer Art (leer für die Arten, die nur erkannt werden).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fields(string $type): array
    {
        $fields = self::typeFields($type);
        return $fields === [] ? [] : $fields + self::DOCUMENT;
    }

    /** @return array<string,array<string,mixed>> */
    private static function typeFields(string $type): array
    {
        return match ($type) {
            'invoice' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'kind'             => ['kind' => 'enum', 'options' => ['annual', 'final'], 'core' => true,
                    'hints' => ['annual' => ['jahres', 'verbrauchsabrechnung', 'turnus'], 'final' => ['schluss', 'endabrechnung', 'endrechnung']],
                    'desc' => 'annual (Jahresabrechnung) or final (Schlussrechnung, Endabrechnung after moving or switching)'],
                'period_from'      => ['kind' => 'date', 'core' => true, 'desc' => 'first day of the billing period (Abrechnungszeitraum von)'],
                'period_to'        => ['kind' => 'date', 'core' => true, 'desc' => 'last day of the billing period (Abrechnungszeitraum bis)'],
                'consumption'      => ['kind' => 'number', 'core' => true, 'desc' => 'consumption of the period in kWh (Verbrauch); for water in m³'],
                'reading_new'      => ['kind' => 'number', 'core' => true, 'desc' => 'meter reading at the end of the period (Zählerstand neu, Endstand)'],
                'reading_new_date' => ['kind' => 'date', 'core' => true, 'desc' => 'date of the new meter reading (Ablesedatum)'],
                'reading_new_estimated' => ['desc' => 'new meter reading: ' . self::ESTIMATED['desc']] + self::ESTIMATED,
                'reading_old'      => ['kind' => 'number', 'desc' => 'meter reading at the start of the period (Zählerstand alt, Anfangsstand)'],
                'reading_old_date' => ['kind' => 'date', 'desc' => 'date of the old meter reading'],
                'reading_old_estimated' => ['desc' => 'old meter reading: ' . self::ESTIMATED['desc']] + self::ESTIMATED,
                'total_amount'     => ['kind' => 'money', 'core' => true, 'desc' => 'total gross amount of the period in EUR (Gesamtbetrag, Rechnungsbetrag brutto)'],
                'advances_paid'    => ['kind' => 'list', 'desc' => 'every advance payment paid in the period, one item per line (geleistete Abschläge)',
                    'items' => ['amount' => ['kind' => 'money', 'desc' => 'amount in EUR'], 'date' => ['kind' => 'date', 'desc' => 'date or month of the payment, if printed']]],
                'advances_total'   => ['kind' => 'money', 'desc' => 'sum of advance payments if printed (Summe Abschläge)'],
                'result_kind'      => ['kind' => 'enum', 'options' => ['credit', 'due'], 'core' => true,
                    'hints' => ['credit' => ['guthaben', 'gutschrift', 'erstatt', 'rückzahl'], 'due' => ['nachzahl', 'nachforderung', 'zu zahlen', 'forderung', 'offen']],
                    'desc' => 'credit (Guthaben) or amount due (Nachzahlung)'],
                'result_amount'    => ['kind' => 'money', 'core' => true, 'desc' => 'amount of the credit or the amount due in EUR'],
                'advance_new'      => ['kind' => 'money', 'core' => true, 'desc' => 'new monthly advance payment as stated (neuer Abschlag), not an average'],
                'advance_new_from' => ['kind' => 'date', 'desc' => 'date from which the new advance payment applies (ab, fällig ab)'],
                'meter_number'     => self::METER_NUMBER,
                'meter_readings'   => self::METER_READINGS,
                'market_location'  => self::MALO,
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
                'gas_factors'      => ['kind' => 'list', 'desc' => 'gas only: conversion factors per period (Zustandszahl, Brennwert)',
                    'items' => ['from' => ['kind' => 'date', 'desc' => 'start of the period'], 'to' => ['kind' => 'date', 'desc' => 'end of the period'],
                                'z' => ['kind' => 'number', 'desc' => 'Zustandszahl, e.g. 0,9512'], 'hs' => ['kind' => 'number', 'desc' => 'Brennwert in kWh/m³, e.g. 11,25']]],
                'working_prices'   => ['kind' => 'list', 'review' => true, 'desc' => 'energy unit prices in ct/kWh with the date they apply from (Arbeitspreis), as printed',
                    'items' => ['from' => ['kind' => 'date', 'desc' => 'valid from'],
                                'ct' => ['kind' => 'price_ct', 'desc' => 'price in ct/kWh as printed: gross if both are printed, otherwise the printed price, also a net one'],
                                'basis' => self::PRICE_BASIS,
                                'vat_pct' => self::VAT_PCT]],
                'base_price'       => ['kind' => 'money', 'review' => true, 'desc' => 'standing charge in EUR (Grundpreis) as printed: gross if both are printed, otherwise the printed price, also a net one'],
                'base_price_period' => self::BASE_PERIOD,
                'base_price_basis' => ['desc' => 'standing charge: ' . self::PRICE_BASIS['desc']] + self::PRICE_BASIS,
                'base_price_vat_pct' => ['desc' => 'standing charge: ' . self::VAT_PCT['desc']] + self::VAT_PCT,
            ],
            'contract_confirmation' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'supply_start'     => ['kind' => 'date', 'core' => true, 'desc' => 'start of supply (Lieferbeginn, Vertragsbeginn)'],
                'tariff_name'      => ['kind' => 'text', 'core' => true, 'desc' => 'name of the tariff or product (Tarif)'],
                'working_price'    => ['kind' => 'price_ct', 'core' => true, 'desc' => 'energy unit price in ct/kWh (Arbeitspreis), gross'],
                'base_price'       => ['kind' => 'money', 'core' => true, 'desc' => 'standing charge in EUR (Grundpreis), gross'],
                'base_price_period' => self::BASE_PERIOD + ['core' => true],
                'min_term_months'  => ['kind' => 'number', 'desc' => 'minimum term in months (Mindestlaufzeit, Erstlaufzeit)'],
                'notice_period'    => ['kind' => 'text', 'desc' => 'notice period as printed, e.g. "1 Monat" or "6 Wochen" (Kündigungsfrist)'],
                'advance'          => ['kind' => 'money', 'core' => true, 'desc' => 'monthly advance payment (Abschlag)'],
                'bonus'            => ['kind' => 'money', 'desc' => 'bonus in EUR (Neukundenbonus, Sofortbonus)'],
                'price_guarantee_until' => ['kind' => 'date', 'desc' => 'price guarantee until (Preisgarantie bis)'],
                'vat_pct'          => ['desc' => 'VAT rate in % that the prices include, as printed (inkl. 19 % USt), e.g. 19; null if not printed'] + self::VAT_PCT,
                'meter_number'     => self::METER_NUMBER,
                'market_location'  => self::MALO,
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
            ],
            'price_change' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'valid_from'       => ['kind' => 'date', 'core' => true, 'desc' => 'date the new prices apply from (gültig ab)'],
                'working_price_old' => ['kind' => 'price_ct', 'desc' => 'old energy unit price in ct/kWh, gross'],
                'working_price_new' => ['kind' => 'price_ct', 'core' => true, 'desc' => 'new energy unit price in ct/kWh, gross'],
                'base_price_old'   => ['kind' => 'money', 'desc' => 'old standing charge in EUR, gross'],
                'base_price_new'   => ['kind' => 'money', 'core' => true, 'desc' => 'new standing charge in EUR, gross'],
                'base_price_period' => self::BASE_PERIOD,
                'vat_pct'          => ['desc' => 'VAT rate in % that the new prices include, as printed (inkl. 19 % USt), e.g. 19; null if not printed'] + self::VAT_PCT,
                'reason'           => ['kind' => 'text', 'desc' => 'reason given for the change, short'],
                'special_termination_until' => ['kind' => 'date', 'desc' => 'special right of termination until (Sonderkündigungsrecht bis)'],
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
            ],
            'cancellation' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'supply_end'       => ['kind' => 'date', 'core' => true, 'desc' => 'last day of supply (Lieferende, Vertragsende)'],
                'cancel_kind'      => ['kind' => 'enum', 'options' => ['customer', 'supplier', 'switch'], 'core' => true,
                    'hints' => ['customer' => ['ihre kündigung', 'kündigungsbestätigung', 'bestätigen'], 'supplier' => ['kündigen wir', 'wir kündigen'], 'switch' => ['wechsel', 'neuen lieferanten']],
                    'desc' => 'customer (we confirm your termination), supplier (the supplier terminates), switch (ends because of a supplier switch)'],
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
                'meter_number'     => self::METER_NUMBER,
            ],
            'supply_start' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'supply_start'     => ['kind' => 'date', 'core' => true, 'desc' => 'start of supply (Lieferbeginn)'],
                'meter_number'     => self::METER_NUMBER + ['core' => true],
                'reading_start'    => ['kind' => 'number', 'desc' => 'meter reading at the start of supply (Zählerstand bei Lieferbeginn)'],
                'market_location'  => self::MALO,
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
            ],
            'meter_change' => [
                'utility'          => self::UTILITY_FIELD,
                'change_date'      => ['kind' => 'date', 'core' => true, 'desc' => 'date of the exchange (Wechseldatum, Ausbau/Einbau)'],
                'old_meter_number' => ['kind' => 'text', 'core' => true, 'desc' => 'number of the removed meter (alter Zähler, Ausbau)'],
                'old_reading'      => ['kind' => 'number', 'core' => true, 'desc' => 'final reading of the removed meter (Endstand, Ausbaustand)'],
                'new_meter_number' => ['kind' => 'text', 'core' => true, 'desc' => 'number of the new meter (neuer Zähler, Einbau)'],
                'new_reading'      => ['kind' => 'number', 'core' => true, 'desc' => 'initial reading of the new meter (Anfangsstand, Einbaustand)'],
                'market_location'  => self::MALO,
            ],
            'reading_request' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'requested_by'     => ['kind' => 'date', 'core' => true, 'desc' => 'report the reading by this date (bis zum)'],
                'meter_number'     => self::METER_NUMBER + ['core' => true],
            ],
            'advance_change' => [
                'utility'          => self::UTILITY_FIELD,
                'supplier'         => self::SUPPLIER,
                'advance_new'      => ['kind' => 'money', 'core' => true, 'desc' => 'new monthly advance payment as stated (neuer Abschlag)'],
                'valid_from'       => ['kind' => 'date', 'core' => true, 'desc' => 'date from which it applies (ab)'],
                'contract_number'  => self::CONTRACT_NUMBER,
                'customer_number'  => self::CUSTOMER_NUMBER,
            ],
            default => [],
        };
    }

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** Wird die Art ausgelesen (Felder) oder nur erkannt und abgelegt? */
    public static function extracts(string $type): bool
    {
        return self::isType($type) && !in_array($type, self::RECOGNIZE_ONLY, true);
    }

    /**
     * Schema einer Art, wie es ein Anbieter bekommt (ExtractionProvider::extract):
     * {type, description, version, fields}. Die Felder ohne `hints` — die
     * braucht nur die Belegprüfung.
     *
     * @return array<string,mixed>
     */
    public static function forProvider(string $type): array
    {
        $fields = [];
        foreach (self::fields($type) as $name => $f) {
            $out = ['kind' => $f['kind'], 'description' => $f['desc']];
            if (isset($f['options'])) $out['options'] = $f['options'];
            if (isset($f['items'])) {
                $out['items'] = array_map(fn(array $i) => ['kind' => $i['kind'], 'description' => $i['desc']], $f['items']);
            }
            $fields[$name] = $out;
        }
        return ['type' => $type, 'description' => self::TYPE_DESC[$type] ?? '', 'version' => self::VERSION, 'fields' => $fields];
    }
}
