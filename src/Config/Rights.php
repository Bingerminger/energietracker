<?php
declare(strict_types=1);

namespace Energietracker\Config;

use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1035, Paket basis) — Rechtsregeln für „Deine Rechte als Kunde“, eine Stelle.
 *
 * Jede Regel trägt ihre Anzeigeform (`norm`), die Quelle (`url`), den Tag des Abrufs
 * (`checked_on`), den Stand der Prüfung (`status`) und die Zeile im Prüfbericht
 * (`report`, Konzept v3.11.0 §12; Doku: docs/referenz/rechtsgrundlagen.md). Status:
 *   W  Wortlaut der amtlichen Quelle abgerufen und zusammengefasst
 *   S  Sekundärquelle (Verbraucherzentrale, vzbv, Fachportal)
 *   G  gegenlesen (aus Kenntnis, nicht abgerufen, oder Detail offen)
 *   U  unsicher — nie in einem Hinweis (RightsService lehnt den Schlüssel ab)
 * `cite: false` heißt: nur für die Gestaltung, nie im Text für Nutzer (Prüfbericht).
 * `utilities` (null = jede Art) und `supply_kinds` (`basic` Grundversorgung, `special`
 * Sondervertrag; null = beide) sagen, wofür eine Regel gilt (applicable()).
 *
 * Länderfähig (Entscheidung 1a): je Land ein Block, nur DE befüllt. Ein Land ohne Block
 * bekommt keine Hinweise und keine Briefe (supports()).
 *
 * Getrennt davon die Schwellen der App (keine Gesetzeswerte): Konstanten unten mit
 * Präfix-Kommentar „App-Schwelle“. Die Doku nennt sie über DocumentedThresholdsTest,
 * sobald ein Fachpaket sie verwendet und beschreibt.
 *
 * Vor dem Release: jede Zeile gegen den dann geltenden Wortlaut lesen und `checked_on`
 * setzen (Konzept §12). Keine Rechtsaussage ohne Eintrag hier.
 */
final class Rights
{
    /** Tag des Abrufs aller Quellen (Konzept §12). */
    public const CHECKED_ON = '2026-11-03';

    public const STATUSES = ['W', 'S', 'G', 'U'];
    public const SUPPLY_KINDS = ['basic', 'special'];

    /**
     * „Energie“ im Sinne des EnWG: Elektrizität, Gas (und Wasserstoff), § 3 Nr. 31 EnWG
     * (Prüfbericht 55). Nur dort gibt es Grundversorgung, die Fristen des § 40c und die
     * Sonderkündigung nach § 41 Abs. 5 EnWG; nicht bei Fernwärme und Wasser.
     */
    public const ENERGY_UTILITIES = ['DE' => ['strom', 'gas']];

    private const G = 'https://www.gesetze-im-internet.de/';

    /**
     * Regeln je Land. Schlüssel: `<land>.<gesetz>.<paragraf>[.<absatz>[.<satz/nr>]]`, für
     * Quellen ohne Paragraf `<land>.<herausgeber>.<thema>`.
     *
     * @var array<string, array<string, array{norm:string, url:string, checked_on:string, status:string,
     *      report:int, utilities:?list<string>, supply_kinds:?list<string>, cite?:bool}>>
     */
    public const RULES = ['DE' => [
        // ── EnWG: Abrechnung (A1, A4) ──
        'de.enwg.40c.2' => ['norm' => '§ 40c Abs. 2 EnWG', 'url' => self::G . 'enwg_2005/__40c.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 1, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40c.3' => ['norm' => '§ 40c Abs. 3 EnWG', 'url' => self::G . 'enwg_2005/__40c.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 2, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40c.1' => ['norm' => '§ 40c Abs. 1 EnWG', 'url' => self::G . 'enwg_2005/__40c.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 3, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40b.1' => ['norm' => '§ 40b Abs. 1 EnWG', 'url' => self::G . 'enwg_2005/__40b.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 4, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40.2.6' => ['norm' => '§ 40 Abs. 2 Satz 1 Nr. 6 EnWG', 'url' => self::G . 'enwg_2005/__40.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 5, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40.2.13' => ['norm' => '§ 40 Abs. 2 Satz 1 Nr. 13 EnWG', 'url' => self::G . 'enwg_2005/__40.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 6, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40.3.4' => ['norm' => '§ 40 Abs. 3 Nr. 4 EnWG', 'url' => self::G . 'enwg_2005/__40.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 7, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        // ── EnWG/GVV: Schätzung (A5) ──
        'de.enwg.40a.2.s1' => ['norm' => '§ 40a Abs. 2 Satz 1, 2 EnWG', 'url' => self::G . 'enwg_2005/__40a.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 8, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40a.2.s3' => ['norm' => '§ 40a Abs. 2 Satz 3 EnWG', 'url' => self::G . 'enwg_2005/__40a.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 9, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.40a.1' => ['norm' => '§ 40a Abs. 1 Satz 4, 5 EnWG', 'url' => self::G . 'enwg_2005/__40a.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 10, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.stromgvv.11' => ['norm' => '§ 11 Abs. 1 StromGVV', 'url' => self::G . 'stromgvv/__11.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 11, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.11' => ['norm' => '§ 11 Abs. 1 GasGVV', 'url' => self::G . 'gasgvv/__11.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 11, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        // ── Abschläge (A2) ──
        'de.stromgvv.13' => ['norm' => '§ 13 StromGVV', 'url' => self::G . 'stromgvv/__13.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 12, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.13' => ['norm' => '§ 13 GasGVV', 'url' => self::G . 'gasgvv/__13.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 12, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        'de.enwg.41b.3' => ['norm' => '§ 41b Abs. 3 EnWG', 'url' => self::G . 'enwg_2005/__41b.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 13, 'utilities' => ['strom', 'gas'], 'supply_kinds' => ['special']],
        'de.avbfernwaermev.25' => ['norm' => '§ 25 AVBFernwärmeV', 'url' => self::G . 'avbfernw_rmev/__25.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 14, 'utilities' => ['fernwaerme'], 'supply_kinds' => null],
        'de.avbwasserv.25' => ['norm' => '§ 25 AVBWasserV', 'url' => self::G . 'avbwasserv/__25.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 15, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.lg_ffm.3_06_o_13_23' => ['norm' => 'LG Frankfurt a. M., 30.05.2023, 3-06 O 13/23',
            'url' => 'https://www.vzbv.de/urteile/gaspreise-vzbv-stoppt-ueberhoehte-abschlaege', 'checked_on' => self::CHECKED_ON,
            'status' => 'S', 'report' => 16, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.vz.advance' => ['norm' => 'Verbraucherzentrale Niedersachsen: Abschlagszahlungen',
            'url' => 'https://www.verbraucherzentrale-niedersachsen.de/wissen/energie/preise-tarife-anbieterwechsel/abschlagszahlungen-fuer-strom-und-gas-richtig-berechnen-120062',
            'checked_on' => self::CHECKED_ON, 'status' => 'S', 'report' => 17, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        // ── Preisänderung und Kündigung (A3, C) ──
        'de.enwg.41.5.s1' => ['norm' => '§ 41 Abs. 5 Satz 1 bis 3 EnWG', 'url' => self::G . 'enwg_2005/__41.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 18, 'utilities' => ['strom', 'gas'], 'supply_kinds' => ['special']],
        'de.enwg.41.5.s4' => ['norm' => '§ 41 Abs. 5 Satz 4 EnWG', 'url' => self::G . 'enwg_2005/__41.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 19, 'utilities' => ['strom', 'gas'], 'supply_kinds' => ['special']],
        'de.enwg.41.6' => ['norm' => '§ 41 Abs. 6 EnWG', 'url' => self::G . 'enwg_2005/__41.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 20, 'utilities' => ['strom', 'gas'], 'supply_kinds' => ['special']],
        'de.vz.special_termination' => ['norm' => 'Verbraucherzentrale Niedersachsen: Sonderkündigungsrecht (Stand 24.04.2026)',
            'url' => 'https://www.verbraucherzentrale-niedersachsen.de/themen/energie-wohnen/energiepreise-anbieterwechsel/preiserhoehung-wenn-energieanbieter-das-sonderkuendigungsrecht-verweigern',
            'checked_on' => self::CHECKED_ON, 'status' => 'S', 'report' => 21, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.41b.1' => ['norm' => '§ 41b Abs. 1 Satz 2 EnWG', 'url' => self::G . 'enwg_2005/__41b.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 22, 'utilities' => ['strom', 'gas'], 'supply_kinds' => ['special']],
        'de.enwg.41.4' => ['norm' => '§ 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG', 'url' => self::G . 'enwg_2005/__41.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 23, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.stromgvv.5.2' => ['norm' => '§ 5 Abs. 2 StromGVV', 'url' => self::G . 'stromgvv/__5.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 24, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.5.2' => ['norm' => '§ 5 Abs. 2 GasGVV', 'url' => self::G . 'gasgvv/__5.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 24, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        'de.stromgvv.5.3' => ['norm' => '§ 5 Abs. 3 StromGVV', 'url' => self::G . 'stromgvv/__5.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 25, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.5.3' => ['norm' => '§ 5 Abs. 3 GasGVV', 'url' => self::G . 'gasgvv/__5.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 25, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        'de.stromgvv.20' => ['norm' => '§ 20 StromGVV', 'url' => self::G . 'stromgvv/__20.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 26, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.20' => ['norm' => '§ 20 GasGVV', 'url' => self::G . 'gasgvv/__20.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 26, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        'de.stromgvv.17' => ['norm' => '§ 17 Abs. 1 StromGVV', 'url' => self::G . 'stromgvv/__17.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 27, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.17' => ['norm' => '§ 17 Abs. 1 GasGVV', 'url' => self::G . 'gasgvv/__17.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 27, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        'de.stromgvv.18' => ['norm' => '§ 18 StromGVV', 'url' => self::G . 'stromgvv/__18.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 28, 'utilities' => ['strom'], 'supply_kinds' => ['basic']],
        'de.gasgvv.18' => ['norm' => '§ 18 GasGVV', 'url' => self::G . 'gasgvv/__18.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 28, 'utilities' => ['gas'], 'supply_kinds' => ['basic']],
        // ── Fernwärme, Wasser, BGB (B1, B2, A1, A5) ──
        'de.avbfernwaermev.24.4' => ['norm' => '§ 24 Abs. 4 bis 7 AVBFernwärmeV', 'url' => self::G . 'avbfernw_rmev/__24.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 29, 'utilities' => ['fernwaerme'], 'supply_kinds' => null],
        'de.avbfernwaermev.32' => ['norm' => '§ 32 AVBFernwärmeV', 'url' => self::G . 'avbfernw_rmev/__32.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 30, 'utilities' => ['fernwaerme'], 'supply_kinds' => null],
        'de.bgb.310.2' => ['norm' => '§ 310 Abs. 2 BGB', 'url' => self::G . 'bgb/__310.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 31, 'utilities' => ['strom', 'gas', 'fernwaerme', 'wasser'], 'supply_kinds' => null],
        'de.bgb.309.9' => ['norm' => '§ 309 Nr. 9 BGB', 'url' => self::G . 'bgb/__309.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 32, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        // v3.11.0 (Skeptiker H3) — Kündigung nach stillschweigender Verlängerung: nicht sicher belegt, nie im Hinweis
        'de.bgb.309.9.b' => ['norm' => '§ 309 Nr. 9 Buchst. b BGB', 'url' => self::G . 'bgb/__309.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'U', 'report' => 59, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.avbfernwaermev.24.1' => ['norm' => '§ 24 Abs. 1 AVBFernwärmeV', 'url' => self::G . 'avbfernw_rmev/__24.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'U', 'report' => 33, 'utilities' => ['fernwaerme'], 'supply_kinds' => null],
        'de.avbwasserv.24.1' => ['norm' => '§ 24 Abs. 1 AVBWasserV', 'url' => self::G . 'avbwasserv/__24.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 34, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.avbwasserv.20.2' => ['norm' => '§ 20 Abs. 2 AVBWasserV', 'url' => self::G . 'avbwasserv/__20.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 35, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.avbfernwaermev.20.2' => ['norm' => '§ 20 Abs. 2 AVBFernwärmeV', 'url' => self::G . 'avbfernw_rmev/__20.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 35, 'utilities' => ['fernwaerme'], 'supply_kinds' => null],
        'de.avbwasserv.30' => ['norm' => '§ 30 AVBWasserV', 'url' => self::G . 'avbwasserv/__30.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 36, 'utilities' => ['wasser'], 'supply_kinds' => null],
        // ── Bonus (A4) ──
        'de.vz.bonus' => ['norm' => '§ 40c Abs. 3 EnWG (Auslegung der Verbraucherzentrale)',
            'url' => 'https://www.verbraucherzentrale.de/wissen/vertraege-reklamation/kundenrechte/wenn-energieanbieter-guthaben-oder-bonus-nicht-auszahlen-10913',
            'checked_on' => self::CHECKED_ON, 'status' => 'S', 'report' => 37, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.bgh.ix_zr_267_20' => ['norm' => 'BGH, 27.07.2023, IX ZR 267/20', 'url' => 'https://rewis.io/urteile/urteil/f7q-27-07-2023-ix-zr-26720/',
            'checked_on' => self::CHECKED_ON, 'status' => 'S', 'report' => 38, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.bgb.195' => ['norm' => '§§ 195, 199 Abs. 1 BGB', 'url' => self::G . 'bgb/__195.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 39, 'utilities' => null, 'supply_kinds' => null],
        // ── Messstellenbetrieb (A6, nur Strom) ──
        'de.msbg.30' => ['norm' => '§ 30 Abs. 1, 3 MsbG', 'url' => self::G . 'messbg/__30.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 40, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.msbg.30.1.4' => ['norm' => '§ 30 Abs. 1 Nr. 4 MsbG', 'url' => self::G . 'messbg/__30.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 41, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.msbg.30.4' => ['norm' => '§ 30 Abs. 4 bis 6 MsbG', 'url' => self::G . 'messbg/__30.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 42, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.msbg.32.1' => ['norm' => '§ 32 Abs. 1 MsbG', 'url' => self::G . 'messbg/__32.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 43, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.msbg.35.1' => ['norm' => '§ 35 Abs. 1 Nr. 1 MsbG', 'url' => self::G . 'messbg/__35.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 44, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.msbg.29' => ['norm' => '§ 29 MsbG', 'url' => self::G . 'messbg/__29.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 45, 'utilities' => ['strom'], 'supply_kinds' => null],
        'de.bnetza.msb_costs' => ['norm' => 'Bundesnetzagentur: Kosten des Messstellenbetriebs',
            'url' => 'https://www.bundesnetzagentur.de/DE/Vportal/Energie/Metering/_faq/Kosten_table.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'S', 'report' => 46, 'utilities' => ['strom'], 'supply_kinds' => null],
        // ── Gebührenbescheid (A7, Wasser/Abwasser) ──
        'de.vwgo.70.1' => ['norm' => '§ 70 Abs. 1 VwGO', 'url' => self::G . 'vwgo/__70.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 47, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.vwgo.58.2' => ['norm' => '§ 58 Abs. 2 VwGO', 'url' => self::G . 'vwgo/__58.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 48, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.vwgo.68.1' => ['norm' => '§ 68 Abs. 1 Satz 2, § 74 Abs. 1 VwGO', 'url' => self::G . 'vwgo/__68.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 49, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.vwvfg.41.2' => ['norm' => '§ 41 Abs. 2 Satz 1 VwVfG', 'url' => self::G . 'vwvfg/__41.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 50, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.ao.122.2' => ['norm' => '§ 122 Abs. 2 Nr. 1 AO', 'url' => self::G . 'ao_1977/__122.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 50, 'utilities' => ['wasser'], 'supply_kinds' => null],
        // v3.12.0 — späterer Zugang verschiebt die Bekanntgabe (Wortlaut abgerufen 2026-11-09)
        'de.vwvfg.41.2.3' => ['norm' => '§ 41 Abs. 2 Satz 3 VwVfG', 'url' => self::G . 'vwvfg/__41.html', 'checked_on' => '2026-11-09',
            'status' => 'W', 'report' => 50, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.ao.122.2.zugang' => ['norm' => '§ 122 Abs. 2 AO, letzter Halbsatz', 'url' => self::G . 'ao_1977/__122.html', 'checked_on' => '2026-11-09',
            'status' => 'W', 'report' => 50, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.vwgo.80' => ['norm' => '§ 80 Abs. 2 Satz 1 Nr. 1, Abs. 4 Satz 3 VwGO', 'url' => self::G . 'vwgo/__80.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 51, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.bgb.188' => ['norm' => '§§ 187 Abs. 1, 188 Abs. 2, 3 BGB', 'url' => self::G . 'bgb/__188.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'G', 'report' => 52, 'utilities' => null, 'supply_kinds' => null],
        'de.land.vorverfahren' => ['norm' => '§ 110 JustG NRW, Art. 12 AGVwGO (Bayern)',
            'url' => 'https://steuerzahler.de/aktuelles/detail/rechtsschutz-gegenueber-behoerden-das-widerspruchsverfahren/', 'checked_on' => self::CHECKED_ON,
            'status' => 'U', 'report' => 53, 'utilities' => ['wasser'], 'supply_kinds' => null, 'cite' => false],
        // v3.11.0 (Nachprüfung NP1) — Fristende am Samstag, Sonntag oder Feiertag: der nächste Werktag
        // (Wortlaut gelesen 03.11.2026); Rechtsbehelfsfrist nach VwGO, im Verwaltungsverfahren VwVfG, für Abgaben AO
        'de.vwgo.57.2' => ['norm' => '§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO', 'url' => self::G . 'vwgo/__57.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 60, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.vwvfg.31.3' => ['norm' => '§ 31 Abs. 3 VwVfG', 'url' => self::G . 'vwvfg/__31.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 60, 'utilities' => ['wasser'], 'supply_kinds' => null],
        'de.ao.108.3' => ['norm' => '§ 108 Abs. 3 AO', 'url' => self::G . 'ao_1977/__108.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 60, 'utilities' => ['wasser'], 'supply_kinds' => null],
        // Welche Feiertage bundesweit gelten (NATIONWIDE_HOLIDAYS): der 3. Oktober nach dem Einigungsvertrag
        // (gelesen), die übrigen acht nach den Feiertagsgesetzen der Länder (nicht einzeln abgerufen: G)
        'de.feiertage' => ['norm' => 'Art. 2 Abs. 2 Einigungsvertrag, Feiertagsgesetze der Länder', 'url' => self::G . 'einigvtr/art_2.html',
            'checked_on' => self::CHECKED_ON, 'status' => 'G', 'report' => 61, 'utilities' => ['wasser'], 'supply_kinds' => null],
        // ── Beschwerde, Begriffe, Gestaltung ──
        'de.enwg.111a' => ['norm' => '§ 111a EnWG', 'url' => self::G . 'enwg_2005/__111a.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 54, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.3.31' => ['norm' => '§ 3 Nr. 31, Nr. 57 EnWG', 'url' => self::G . 'enwg_2005/__3.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'W', 'report' => 55, 'utilities' => null, 'supply_kinds' => null],
        'de.bgh.i_zr_113_20' => ['norm' => 'BGH, 09.09.2021, I ZR 113/20',
            'url' => 'https://www.lto.de/recht/juristen/b/bgh-urteil-izr11320-vertragsgenerator-smartlaw-keine-unzulaessige-rechtsdienstleistung-rak-hamburg-rdg-legal-tech',
            'checked_on' => self::CHECKED_ON, 'status' => 'S', 'report' => 56, 'utilities' => null, 'supply_kinds' => null, 'cite' => false],
        'de.bnetza.bills' => ['norm' => 'Bundesnetzagentur: Rechnungen',
            'url' => 'https://www.bundesnetzagentur.de/DE/Vportal/Energie/RechnungenSperrungen/Rechnungen-table.html', 'checked_on' => self::CHECKED_ON,
            'status' => 'S', 'report' => 57, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
        'de.enwg.2026' => ['norm' => 'EnWG, Änderung durch BGBl. 2026 I Nr. 226', 'url' => 'https://www.buzer.de/41_EnWG.htm', 'checked_on' => self::CHECKED_ON,
            'status' => 'U', 'report' => 58, 'utilities' => ['strom', 'gas'], 'supply_kinds' => null],
    ]];

    /**
     * Gesetzliche Fristen je Land (keine App-Schwellen):
     *   announce_special_months  Sondervertrag: Unterrichtung spätestens einen Monat vor der
     *                            Preisänderung (Haushaltskunden, § 41 Abs. 5 Satz 2 EnWG)
     *   announce_basic_days      Grundversorgung: öffentliche Bekanntgabe mindestens sechs
     *                            Wochen vorher, wirksam zum Monatsbeginn (§ 5 Abs. 2 GVV)
     *   notice_postal_days       Bescheid per Post: bekannt gegeben am vierten Tag nach der
     *                            Aufgabe zur Post (§ 41 Abs. 2 Satz 1 VwVfG, § 122 Abs. 2 Nr. 1 AO)
     *   notice_months            Rechtsbehelf binnen eines Monats nach Bekanntgabe (§ 70 Abs. 1,
     *                            § 74 Abs. 1 VwGO); Fristende nach §§ 187 Abs. 1, 188 Abs. 2, 3 BGB
     * Die Frist der Abrechnung (§ 40c Abs. 2 EnWG, 42 Tage) steht seit v3.5.0 in
     * Countries::BILL_DEADLINE_DAYS (eine Quelle mit „Abrechnung erwartet“).
     */
    public const DEADLINES = ['DE' => [
        'announce_special_months' => 1, 'announce_basic_days' => 42,
        'notice_postal_days' => 4, 'notice_months' => 1,
    ]];

    /**
     * v3.11.0 (Nachprüfung NP1) — gesetzliche Feiertage, die in jedem Land des Landes gelten
     * (Prüfbericht 61): feste Tage `MM-DD` und Abstände zum Ostersonntag in Tagen (Karfreitag −2,
     * Ostermontag +1, Christi Himmelfahrt +39, Pfingstmontag +50). Fällt das Ende einer Frist auf
     * Samstag, Sonntag oder einen dieser Tage, endet sie am nächsten Werktag (Prüfbericht 60).
     * Feiertage nur einzelner Länder kennt die App nicht; das sagt der Text.
     *
     * @var array<string, array{fixed:list<string>, easter:list<int>}>
     */
    public const NATIONWIDE_HOLIDAYS = ['DE' => [
        'fixed' => ['01-01', '05-01', '10-03', '12-25', '12-26'],
        'easter' => [-2, 1, 39, 50],
    ]];

    /**
     * Preisobergrenzen des Messstellenbetriebs, je Land eine datierte Liste (`from`, wie die
     * Gas-Umrechnung): brutto je Jahr und Zählpunkt, Anteil des Anschlussnutzers, für den
     * grundzuständigen Messstellenbetreiber (MsbG in der Fassung vom 23.12.2025, BGBl. 2025 I
     * Nr. 347; Prüfbericht 40 bis 46). Geprüft erst ab 2026-01-01 (Entscheidung 4a): Die Beträge
     * der Fassungen 2023 und 02/2025 sind nicht belegt (Prüfbericht 40: U).
     *
     * Je Fall: `device` (mme moderne Messeinrichtung, imsys intelligentes Messsystem), die
     * Fallgruppe über Jahresverbrauch (`kwh_above` < kWh ≤ `kwh_upto`), Leistung der Anlage
     * (`kw_above` < kW ≤ `kw_upto`) oder eine Vereinbarung nach § 14a EnWG (`para_14a`); jedes
     * Merkmal allein genügt, mehrere → die höchste Grenze (§ 30 Abs. 5 MsbG). `cap_eur: null` =
     * angemessenes Entgelt, kein fester Betrag (§ 30 Abs. 1 Nr. 1). Ohne einschlägige Gruppe
     * gilt beim intelligenten Messsystem der optionale Einbau (§ 30 Abs. 3).
     *
     * @var array<string, list<array{from:string, cases:list<array<string,mixed>>}>>
     */
    public const METERING_CAPS = ['DE' => [
        ['from' => '2026-01-01', 'cases' => [
            ['case' => 'mme', 'device' => 'mme', 'cap_eur' => 25.0, 'rule' => 'de.msbg.32.1'],
            ['case' => 'imsys_optional', 'device' => 'imsys', 'cap_eur' => 30.0, 'rule' => 'de.msbg.30'],
            ['case' => 'imsys_6000', 'device' => 'imsys', 'kwh_above' => 6000, 'kwh_upto' => 10000, 'cap_eur' => 40.0, 'rule' => 'de.msbg.30'],
            ['case' => 'imsys_10000', 'device' => 'imsys', 'kwh_above' => 10000, 'kwh_upto' => 20000, 'kw_above' => 7, 'kw_upto' => 15,
             'para_14a' => true, 'cap_eur' => 50.0, 'rule' => 'de.msbg.30.1.4'],
            ['case' => 'imsys_20000', 'device' => 'imsys', 'kwh_above' => 20000, 'kwh_upto' => 50000, 'kw_above' => 15, 'kw_upto' => 25,
             'cap_eur' => 110.0, 'rule' => 'de.msbg.30'],
            ['case' => 'imsys_50000', 'device' => 'imsys', 'kwh_above' => 50000, 'kwh_upto' => 100000, 'kw_above' => 25, 'kw_upto' => 100,
             'cap_eur' => 140.0, 'rule' => 'de.msbg.30'],
            ['case' => 'imsys_100000', 'device' => 'imsys', 'kwh_above' => 100000, 'kw_above' => 100, 'cap_eur' => null, 'rule' => 'de.msbg.30'],
        ]],
    ]];

    public const METERING_DEVICES = ['conventional', 'mme', 'imsys'];

    /**
     * v3.11.0 (Paket rechnung, A6) — Zusatzleistung „vorzeitige Ausstattung auf Verlangen“:
     * einmalig bis zu diesem Betrag (§ 35 Abs. 1 Nr. 1 MsbG, Prüfbericht 44). Im Zeitraum eines
     * Zählertauschs meldet die Prüfung einen Mehrbetrag bis dahin nicht (BillChecks::metering).
     */
    public const METERING_ONE_OFF_EUR = ['DE' => 100.0];

    // ── App-Schwellen (keine Gesetzeswerte; Konzept §3, Entscheidung 3b) ──

    /** App-Schwelle (A1): Befund erst, wenn die Rechnung mehr als so viele Tage nach dem Fristende kam (fängt ein Wochenende ab, § 193 BGB ungeklärt). */
    public const BILL_LATE_GRACE_DAYS = 2;
    /** App-Schwelle (A2): Abschlag „deutlich zu hoch“ ab so viel Prozent über der Bemessung … */
    public const ADVANCE_HIGH_PCT = 20.0;
    /** … und mindestens so viel Euro je Rate. */
    public const ADVANCE_HIGH_MIN_EUR = 10.0;
    /** App-Schwelle (A5): eigene Ablesung höchstens so viele Tage vor oder nach dem Ende des Zeitraums. */
    public const ESTIMATE_WINDOW_DAYS = 14;
    /**
     * App-Schwelle (A5): Abweichung des geschätzten Stands von der Fortschreibung in % der Periodenmenge.
     * v3.11.0 (Skeptiker M1) — Periodenmenge aus der Rechnung (Verbrauch laut Rechnung, bei Gas über den
     * Brennwert in m³), sonst wie bisher Verbrauch je Tag × Tage.
     */
    public const ESTIMATE_DEVIATION_PCT = 2.0;
    /**
     * App-Schwelle (A5, v3.11.0 Skeptiker M1): … und eine Wirkung von mindestens so viel Euro. Derselbe
     * Betrag wie beim Abschlag (ADVANCE_HIGH_MIN_EUR): darunter lohnt kein Brief, und eine Hochrechnung
     * des gemeldeten Stands auf das Periodenende bleibt still. Ohne Preise zählt nur die Menge.
     */
    public const ESTIMATE_MIN_EUR = 10.0;
    /** App-Schwelle (A6): Rundung von Tagespreisen beim Messentgelt. */
    public const METERING_TOLERANCE_EUR = 1.0;
    /** App-Schwelle (A4): Gutschrift gilt als Bonus ab Bonusbetrag minus so viel Euro. */
    public const BONUS_TOLERANCE_EUR = 1.0;
    /** App-Schwelle (A3, A7): Ein Termin mit Frist steht so viele Tage vorher unter „Zu tun“. */
    public const DEADLINE_REMIND_DAYS = 14;
    /**
     * App-Schwelle (A7, v3.12.0): So viele Tage nach dem frühesten Fristende (vom Bescheiddatum gerechnet) heißt
     * es „möglicherweise abgelaufen“, der Brief bleibt, der Eintrag unter „Zu tun“ auch; erst danach „abgelaufen“.
     * Das Bescheiddatum ist nicht der Tag der Aufgabe zur Post, das Ende also nur eine Untergrenze.
     */
    public const NOTICE_SOFT_DAYS = 7;

    /** Kennt die App Rechtsregeln für dieses Land? (Entscheidung 1a: nur DE) */
    public static function supports(string $country): bool
    {
        return (self::RULES[$country] ?? []) !== [];
    }

    /**
     * Eine Regel mit ihrem Schlüssel oder null.
     *
     * @return array<string,mixed>|null {key, norm, url, checked_on, status, report, utilities, supply_kinds, cite}
     */
    public static function rule(string $country, string $key): ?array
    {
        $r = self::RULES[$country][$key] ?? null;
        return $r === null ? null : ['key' => $key] + $r + ['cite' => true];
    }

    /** Darf ein Hinweis die Regel nennen? Nicht bei Status U und nicht bei `cite: false`. */
    public static function citable(string $country, string $key): bool
    {
        $r = self::rule($country, $key);
        return $r !== null && $r['status'] !== 'U' && $r['cite'] !== false;
    }

    /**
     * Die Schlüssel, die für eine Verbrauchsart und Versorgungsart gelten (Reihenfolge bleibt).
     * Unbekannte Versorgungsart (null): Regeln beider Arten — der Hinweis nennt dann beide
     * Normen kurz (Entscheidung 6a).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function applicable(string $country, array $keys, string $utility, ?string $supplyKind = null): array
    {
        $out = [];
        foreach ($keys as $k) {
            $r = self::rule($country, $k);
            if ($r === null) continue;
            if ($r['utilities'] !== null && !in_array($utility, $r['utilities'], true)) continue;
            if ($supplyKind !== null && $r['supply_kinds'] !== null && !in_array($supplyKind, $r['supply_kinds'], true)) continue;
            $out[] = $k;
        }
        return $out;
    }

    /** Ist die Art „Energie“ im Sinne des EnWG (Strom, Gas)? Dort gelten Grundversorgung, § 40c und § 41 Abs. 5. */
    public static function isEnergy(string $country, string $utility): bool
    {
        return in_array($utility, self::ENERGY_UTILITIES[$country] ?? [], true);
    }

    // ── Fristen (A1, A3, A7) ─────────────────────────────────────────────

    /**
     * A1 — letzter Tag der gesetzlichen Frist für die Rechnung: Ende des Abrechnungszeitraums
     * bzw. der Belieferung (`final`) + 42 Tage (§ 40c Abs. 2 EnWG, Countries::BILL_DEADLINE_DAYS).
     * null ohne Frist: anderes Land, Fernwärme und Wasser, Zwischenrechnung (`interim`: monatliche
     * Abrechnung, drei Wochen, bildet die App nicht ab).
     */
    public static function billDeadline(string $country, string $utility, string $periodTo, string $kind = 'annual'): ?string
    {
        if ($kind === 'interim' || !Dates::isIsoDate($periodTo)) return null;
        $days = Countries::billDeadlineDays($country, $utility);
        return $days === null ? null : self::plusDays($periodTo, $days);
    }

    /**
     * A1 — Tage nach dem Fristende, wenn die Rechnung als verspätet gilt (mehr als
     * BILL_LATE_GRACE_DAYS nach dem Fristende); sonst null. `$issuedOn` ist das Datum, an dem
     * die Rechnung vorlag: `received_on ?? issued_on`.
     */
    public static function billDaysLate(string $country, string $utility, string $periodTo, string $kind, string $issuedOn): ?int
    {
        $deadline = self::billDeadline($country, $utility, $periodTo, $kind);
        if ($deadline === null || !Dates::isIsoDate($issuedOn)) return null;
        $days = self::daysBetween($deadline, $issuedOn);
        return $days > self::BILL_LATE_GRACE_DAYS ? $days : null;
    }

    /**
     * A3 — die Kündigung zum Tag der Preisänderung muss vorher zugehen: Termin ist der Tag
     * davor (§ 41 Abs. 5 Satz 4 EnWG; Grundversorgung § 5 Abs. 3 GVV; Auslegung der
     * Verbraucherzentrale, Prüfbericht 21). Nennt das Schreiben ein früheres Datum, gilt das
     * frühere als Termin, der Text nennt beide (Entscheidung 7c) — das entscheidet der Aufrufer.
     */
    public static function specialTerminationDeadline(string $validFrom): string
    {
        return self::plusDays($validFrom, -1);
    }

    /** A3, A7 — ab diesem Tag steht ein Termin mit Frist unter „Zu tun“ (App-Schwelle). */
    public static function remindOn(string $deadline): string
    {
        return self::plusDays($deadline, -self::DEADLINE_REMIND_DAYS);
    }

    /**
     * A3, C — Ankündigungsfrist einer Preisänderung. Beide Fälle, der Aufrufer wählt nach
     * `supply_kind` (unbekannt: nur dann ein Befund, wenn auch die mildere Frist verfehlt ist):
     *   special  spätestens einen Monat vor dem Wirksamwerden (§ 41 Abs. 5 Satz 2 EnWG);
     *            `announce_by` = valid_from − 1 Monat (Monatsende geklemmt, Lektion 26)
     *   basic    mindestens sechs Wochen vorher, wirksam zum Monatsbeginn (§ 5 Abs. 2 GVV);
     *            `month_start` false, wenn valid_from kein Monatserster ist
     * null für ein Land ohne Regeln oder ein ungültiges Datum.
     *
     * @return array{days_before:int, special:array{announce_by:string, late:bool},
     *               basic:array{announce_by:string, late:bool, month_start:bool}}|null
     */
    public static function announcement(string $country, string $announcedOn, string $validFrom): ?array
    {
        $d = self::DEADLINES[$country] ?? null;
        if ($d === null || !Dates::isIsoDate($announcedOn) || !Dates::isIsoDate($validFrom)) return null;
        $special = self::plusMonths($validFrom, -(int)$d['announce_special_months']);
        $basic = self::plusDays($validFrom, -(int)$d['announce_basic_days']);
        return [
            'days_before' => self::daysBetween($announcedOn, $validFrom),
            'special' => ['announce_by' => $special, 'late' => $announcedOn > $special],
            'basic' => ['announce_by' => $basic, 'late' => $announcedOn > $basic, 'month_start' => substr($validFrom, 8, 2) === '01'],
        ];
    }

    /**
     * A7 — Frist für Widerspruch oder Klage gegen einen Gebührenbescheid: Bekanntgabe am
     * vierten Tag nach der Aufgabe zur Post (bewusst vom Bescheiddatum: der früheste mögliche
     * Ablauf), mit Zustellungsurkunde der Tag der Zustellung; dann ein Monat (§§ 187 Abs. 1,
     * 188 Abs. 2, 3 BGB: fehlt der Tag im Monat, der letzte) = `nominal_end`.
     * v3.11.0 (Nachprüfung NP1) — fällt dieser Tag auf Samstag, Sonntag oder einen bundesweiten
     * Feiertag, endet die Frist am nächsten Werktag (§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO; § 31 Abs. 3
     * VwVfG; § 108 Abs. 3 AO; Prüfbericht 60): `deadline`. Bis dahin galt ein Bescheid mit Fristende
     * am Sonntag schon am Montag als „abgelaufen“. Termin, Befund, Brief und Kalender nennen
     * `deadline`. null ohne Regeln für das Land.
     * v3.12.0 — ein eingetragener Zugang (`received_on`, „Erhalten am“) nach dem vierten Tag verschiebt die
     * Bekanntgabe: „Dies gilt nicht, wenn der Verwaltungsakt nicht oder zu einem späteren Zeitpunkt
     * zugegangen ist“ (§ 41 Abs. 2 Satz 3 VwVfG; § 122 Abs. 2 AO, letzter Halbsatz; Wortlaut abgerufen
     * 2026-11-09). Ein früherer Zugang ändert nichts (die Fiktion bleibt der vierte Tag), eine Zustellung
     * geht vor. `basis`: served | received | post. Nur mit Zustellung ist das Ende sicher: `soft_until` = `deadline`.
     * Sonst ist es das früheste mögliche, denn den Tag der Aufgabe zur Post kennt die App nicht (das Bescheiddatum
     * ist nicht dieser Tag). Bekannt gegeben ist der Bescheid am späteren von viertem Tag nach der Aufgabe zur Post
     * und Zugang (§ 41 Abs. 2 Satz 1 und 3 VwVfG, § 122 Abs. 2 Nr. 1 AO; Wortlaut auf gesetze-im-internet.de
     * abgerufen 2026-11-10). Bis `soft_until` heißt es „möglicherweise abgelaufen“:
     *   post      Ende + NOTICE_SOFT_DAYS (wie entschieden);
     *   received  das späteste Ende: Zur Post ging der Bescheid spätestens am Tag des Zugangs, der vierte Tag danach
     *             ist die späteste Bekanntgabe. Vorher galt der Zugangstag als sicher, und ein Bescheid, der kurz
     *             vor dem Zugang zur Post ging, hieß bis zu vier Tage zu früh „abgelaufen“.
     *
     * @return array{known_on:string, nominal_end:string, deadline:string, basis:string, soft_until:string}|null
     */
    public static function feeNoticeDeadline(string $country, string $datedOn, ?string $servedOn = null, ?string $receivedOn = null): ?array
    {
        $d = self::DEADLINES[$country] ?? null;
        if ($d === null || !Dates::isIsoDate($datedOn)) return null;
        $postal = self::plusDays($datedOn, (int)$d['notice_postal_days']);
        [$known, $basis] = match (true) {
            $servedOn !== null && Dates::isIsoDate($servedOn)                          => [$servedOn, 'served'],
            $receivedOn !== null && Dates::isIsoDate($receivedOn) && $receivedOn > $postal => [$receivedOn, 'received'],
            default                                                                       => [$postal, 'post'],
        };
        $nominal = self::plusMonths($known, (int)$d['notice_months']);
        $deadline = self::nextWorkingDay($country, $nominal);
        $soft = match ($basis) {
            'post'     => self::plusDays($deadline, self::NOTICE_SOFT_DAYS),
            'received' => max($deadline, self::nextWorkingDay($country,
                              self::plusMonths(self::plusDays((string)$receivedOn, (int)$d['notice_postal_days']), (int)$d['notice_months']))),
            default    => $deadline,
        };
        return ['known_on' => $known, 'nominal_end' => $nominal, 'deadline' => $deadline, 'basis' => $basis, 'soft_until' => $soft];
    }

    /**
     * v3.11.0 (Nachprüfung NP1) — der Tag selbst, wenn er ein Werktag ist, sonst der nächste:
     * kein Samstag, kein Sonntag, kein bundesweiter Feiertag (NATIONWIDE_HOLIDAYS). Ein Land ohne
     * Feiertagsliste kennt nur das Wochenende.
     */
    public static function nextWorkingDay(string $country, string $date): string
    {
        $d = $date;
        for ($i = 0; $i < 10 && self::isNonWorkingDay($country, $d); $i++) $d = self::plusDays($d, 1);
        return $d;
    }

    /** Samstag, Sonntag oder ein bundesweiter gesetzlicher Feiertag (Prüfbericht 60, 61)? */
    public static function isNonWorkingDay(string $country, string $date): bool
    {
        $day = self::day($date);
        if ((int)$day->format('N') >= 6) return true;
        $h = self::NATIONWIDE_HOLIDAYS[$country] ?? null;
        if ($h === null) return false;
        if (in_array($day->format('m-d'), $h['fixed'], true)) return true;
        $easter = self::easterSunday((int)$day->format('Y'));
        return in_array(self::daysBetween($easter, $day->format('Y-m-d')), $h['easter'], true);
    }

    /**
     * Ostersonntag im gregorianischen Kalender (Algorithmus nach Meeus/Jones/Butcher; ohne die
     * PHP-Erweiterung `calendar`, die nicht jede Installation hat).
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOf = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $dayOf);
    }

    // ── Messstellenbetrieb (A6) ──────────────────────────────────────────

    /**
     * A6 — die Tabelle, die an einem Tag gilt (die jüngste mit `from` ≤ Tag), oder null
     * (vor dem Beginn der hinterlegten Tabelle, anderes Land).
     *
     * @return array{from:string, cases:list<array<string,mixed>>}|null
     */
    public static function meteringTableOn(string $country, string $date): ?array
    {
        $hit = null;
        foreach (self::METERING_CAPS[$country] ?? [] as $t) {
            if ($t['from'] <= $date && ($hit === null || $t['from'] > $hit['from'])) $hit = $t;
        }
        return $hit;
    }

    /**
     * A6 — Preisobergrenze für einen Zählpunkt an einem Tag: die höchste einschlägige
     * Fallgruppe (§ 30 Abs. 5 MsbG), beim intelligenten Messsystem ohne Gruppe der optionale
     * Einbau. Unbekannter Verbrauch oder unbekannte Leistung (null) zählen als „nicht
     * einschlägig“. null: keine Tabelle für den Tag, konventioneller oder unbekannter Zähler.
     *
     * @return array{case:string, cap_eur:?float, rule:string, from:string}|null
     */
    public static function meteringCap(string $country, string $date, string $device, ?float $kwhPerYear, ?float $pvKw = null, bool $para14a = false): ?array
    {
        $table = self::meteringTableOn($country, $date);
        if ($table === null || !in_array($device, ['mme', 'imsys'], true)) return null;
        // v3.12.0 — Grenzen gerundet vergleichen: Ein Jahreswert aus Monaten oder einer hochgerechneten
        // Rechnung trägt Gleitkomma-Reste (6000 ÷ 365 × 365 = 6000,000000000001), und „über 6 000“ heißt
        // mehr als die Grenze, nicht deren Rundungsrest. Sechs Stellen sind feiner als jedes Zählwerk.
        $kwhPerYear = $kwhPerYear === null ? null : round($kwhPerYear, 6);
        $pvKw = $pvKw === null ? null : round($pvKw, 6);
        $pick = null;
        $fallback = null;
        foreach ($table['cases'] as $c) {
            if ($c['device'] !== $device) continue;
            $byKwh = isset($c['kwh_above']) && $kwhPerYear !== null && $kwhPerYear > $c['kwh_above']
                && (!isset($c['kwh_upto']) || $kwhPerYear <= $c['kwh_upto']);
            $byKw = isset($c['kw_above']) && $pvKw !== null && $pvKw > $c['kw_above']
                && (!isset($c['kw_upto']) || $pvKw <= $c['kw_upto']);
            $by14a = !empty($c['para_14a']) && $para14a;
            $grouped = isset($c['kwh_above']) || isset($c['kw_above']) || !empty($c['para_14a']);
            if (!$grouped) { $fallback = $c; continue; }
            if (!$byKwh && !$byKw && !$by14a) continue;
            // höchste Grenze; „angemessen“ (null) steht über jedem Betrag
            if ($pick === null || $c['cap_eur'] === null || ($pick['cap_eur'] !== null && $c['cap_eur'] > $pick['cap_eur'])) $pick = $c;
        }
        $c = $pick ?? $fallback;
        return $c === null ? null : ['case' => (string)$c['case'], 'cap_eur' => $c['cap_eur'] === null ? null : (float)$c['cap_eur'],
                                     'rule' => (string)$c['rule'], 'from' => $table['from']];
    }

    // ── Datumsrechnung (Kalendertage in UTC: keine Zeitumstellung, Lektion 87/100) ──

    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private static function plusDays(string $date, int $days): string
    {
        return self::day($date)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Monate über den Monatsersten, Tag auf das Monatsende geklemmt (Lektion 26, wie ContractService::addMonthsClamped). */
    private static function plusMonths(string $date, int $months): string
    {
        $d = self::day($date);
        $target = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $day = min((int)$d->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    /** Tage von `$from` bis `$to` (negativ, wenn `$to` davor liegt). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int)self::day($from)->diff(self::day($to))->format('%r%a');
    }
}
