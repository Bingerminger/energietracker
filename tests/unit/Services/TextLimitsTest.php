<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Mcp\Tools\WriteTools;
use Energietracker\Services\PeriodService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TenancyService;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\TextLimits;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket F) — Höchstlängen für Freitext (Bekannte Grenze v3.12.0): eine Liste
 * (Support\TextLimits), dieselbe Grenze über die Dienste, im Backup-Import, im CSV-Import, für die KI und
 * an der Oberfläche; zu lang ist ein Fehler mit Feld und Grenze, gekürzt wird nie; Bestand über der
 * Grenze bleibt lesbar und sperrt keinen anderen Schreibweg.
 */
#[CoversClass(TextLimits::class)]
#[CoversClass(BackupService::class)]
final class TextLimitsTest extends ServiceTestCase
{
    private function tooLong(callable $fn): LocalizedException
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            self::assertSame('errors.common.tooLong', $e->key, $e->getMessage());
            return $e;
        }
        self::fail('zu lang wurde angenommen');
    }

    public function testTheBrowserKnowsTheSameLimitsAsTheServer(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/public/js/lib/text-limits.js');
        foreach (['NAME', 'COMPANY', 'TITLE', 'NOTE', 'SHORT_NOTE', 'NUMBER', 'PERSON', 'MARK', 'MCP_NOTE', 'DOC_VALUE'] as $c) {
            self::assertMatchesRegularExpression('/export const ' . $c . ' = ' . constant(TextLimits::class . '::' . $c) . ';/', $js, $c);
        }
        // jede Zeile von LIMITS: dieselben Felder mit denselben Zahlen (Namen der Konstanten aufgelöst)
        preg_match('/export const LIMITS = \{(.*?)\n\};/s', $js, $m);
        $const = ['NAME' => TextLimits::NAME, 'COMPANY' => TextLimits::COMPANY, 'TITLE' => TextLimits::TITLE, 'NOTE' => TextLimits::NOTE, 'SHORT_NOTE' => TextLimits::SHORT_NOTE,
                  'NUMBER' => TextLimits::NUMBER, 'PERSON' => TextLimits::PERSON, 'MARK' => TextLimits::MARK];
        $js = [];
        foreach (preg_split('/\n  (?=[a-z_]+: \{)/', "\n" . trim($m[1])) as $block) {
            if (!preg_match('/^\s*([a-z_]+): \{(.*)\}/s', $block, $b)) continue;
            preg_match_all("/'?([a-z_.]+)'?: ([A-Z_]+|\d+)/", $b[2], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) $js[$b[1]][$p[1]] = ctype_digit($p[2]) ? (int)$p[2] : $const[$p[2]];
        }
        self::assertSame(TextLimits::FIELDS, $js);
    }

    /** Die Tabelle im Datenmodell (DE und EN) ist die Liste, Zeile für Zeile (doc-claim: text-limits). */
    public function testTheDataModelListsEveryLimit(): void
    {
        $want = [];
        foreach (TextLimits::FIELDS as $pot => $fields) foreach ($fields as $f => $max) $want[] = "$pot|$f|$max";
        foreach (['docs/referenz/datenmodell.md', 'docs/en/referenz/datenmodell.md'] as $rel) {
            $md = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString('<!-- doc-claim: text-limits -->', $md, $rel);
            $part = explode('<!-- doc-claim: text-limits -->', $md, 2)[1];
            preg_match_all('/^\| `([a-z_]+)` \| `([a-z_.]+)` \| (\d+) \|$/m', explode("\n\n", ltrim($part), 2)[0], $rows, PREG_SET_ORDER);
            self::assertSame($want, array_map(fn($r) => "$r[1]|$r[2]|$r[3]", $rows), $rel);
            self::assertStringContainsString((string)TextLimits::MCP_NOTE, $part, $rel);
        }
    }

    public function testTooLongIsAnErrorWithFieldAndLimitAndNothingIsStored(): void
    {
        $mid = (string)$this->meters->list('gas')[0]['id'];
        $e = $this->tooLong(fn() => $this->meters->update('gas', $mid, ['notes' => str_repeat('a', TextLimits::NOTE + 1)]));
        self::assertSame(['field' => 'notes', 'max' => '2000', 'length' => '2001'], $e->params);
        self::assertSame('', (string)($this->meters->get('gas', $mid)['notes'] ?? ''), 'nichts gespeichert');
        // genau an der Grenze geht es; Leerraum am Rand zählt nicht (gespeichert wird getrimmt verglichen)
        $this->meters->update('gas', $mid, ['notes' => str_repeat('ä', TextLimits::NOTE)]);
        $this->meters->update('gas', $mid, ['name' => '  ' . str_repeat('n', TextLimits::NAME) . '  ']);
        $this->tooLong(fn() => $this->meters->create('gas', ['name' => str_repeat('n', TextLimits::NAME + 1)]));
        $this->tooLong(fn() => $this->readings->create('gas', ['meter_id' => $mid, 'date' => '2025-01-01', 'counter' => 1,
            'note' => str_repeat('x', TextLimits::NOTE + 1)]));
        $this->tooLong(fn() => $this->contracts->create('gas', ['meter_id' => $mid, 'provider' => str_repeat('p', TextLimits::COMPANY + 1), 'start' => '2025-01-01']));
        $this->tooLong(fn() => $this->contracts->create('gas', ['meter_id' => $mid, 'provider' => 'P', 'start' => '2025-01-01',
            'contract_number' => str_repeat('1', 65)]));
        $this->tooLong(fn() => $this->meters->createGroup('gas', ['name' => str_repeat('g', 121)]));
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->tooLong(fn() => $rem->create(['title' => str_repeat('t', TextLimits::TITLE + 1), 'next_due' => '2026-12-01']));
    }

    /** Bisher still gekürzt: Rechnung, Mietverhältnis, Zeitraum, Tankstand, Zäsur, Vertragsnummer. */
    public function testWhatWasCutSilentlyIsNowAnError(): void
    {
        $mid = (string)$this->meters->list('gas')[0]['id'];
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $base = ['meter_id' => $mid, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31'];
        $this->tooLong(fn() => $bills->create('gas', $base + ['note' => str_repeat('n', 2001)]));
        $this->tooLong(fn() => $bills->create('gas', $base + ['invoice_number' => str_repeat('1', 81)]));
        $this->tooLong(fn() => $bills->create('gas', $base + ['items' => [['label' => str_repeat('l', 121), 'amount_eur' => 1]]]));
        $ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->tooLong(fn() => $ten->create(['start' => '2025-01-01', 'label' => str_repeat('w', 121)]));
        $this->tooLong(fn() => $ten->create(['start' => '2025-01-01', 'notes' => str_repeat('w', 2001)]));
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $this->meters->update('wasser', (string)$this->meters->list('wasser')[0]['id'], ['capture' => 'period']);
        $wid = (string)$this->meters->list('wasser')[0]['id'];
        // bis v3.12 auf 500 gekürzt, jetzt bis 2000 erlaubt und darüber ein Fehler
        $p = $periods->create('wasser', ['meter_id' => $wid, 'from' => '2025-01-01', 'to' => '2025-01-31', 'value' => 3, 'note' => str_repeat('z', 1500)]);
        self::assertSame(1500, mb_strlen((string)$p['note']));
        $this->tooLong(fn() => $periods->create('wasser', ['meter_id' => $wid, 'from' => '2025-02-01', 'to' => '2025-02-28', 'value' => 3, 'note' => str_repeat('z', 2001)]));
        $this->tooLong(fn() => $this->meters->update('gas', $mid, ['baseline_events' => [['date' => '2024-06-01', 'label' => str_repeat('b', 81)]]]));
    }

    /**
     * Bestand über der Grenze (älter als v3.13 oder von Hand): lesbar, nie gekürzt; ein Schreibweg, der das Feld
     * nicht mitschickt, geht; wer es mitschickt, bekommt den Fehler. Listen am Datensatz (Sonderzahlungen) schickt
     * auch BillService::book ganz: Ein unveränderter Eintrag sperrt dort nichts.
     */
    public function testExistingDataOverTheLimitStaysReadableAndBlocksOnlyItsOwnField(): void
    {
        $mid = (string)$this->meters->list('gas')[0]['id'];
        $c = $this->contracts->create('gas', ['meter_id' => $mid, 'provider' => 'P', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 50]]]);
        $long = str_repeat('Alt ', 700);   // 2800 Zeichen
        $all = $this->store->read('gas/contracts.json', []);
        $all[0]['notes'] = $long;
        $all[0]['special_payments'] = [['id' => 'sp_alt', 'date' => '2024-06-01', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 5.0, 'note' => str_repeat('s', 300)]];
        $this->store->write('gas/contracts.json', $all);

        self::assertSame($long, $this->contracts->get('gas', (string)$c['id'])['notes'], 'lesbar');
        $this->contracts->update('gas', (string)$c['id'], ['tariff_name' => 'Neu']);   // anderes Feld: geht
        self::assertSame($long, $this->contracts->get('gas', (string)$c['id'])['notes'], 'nie gekürzt');
        $e = $this->tooLong(fn() => $this->contracts->update('gas', (string)$c['id'], ['notes' => $long]));
        self::assertSame('notes', $e->params['field']);
        self::assertSame($long, $this->contracts->get('gas', (string)$c['id'])['notes']);
        // die ganze Liste der Sonderzahlungen mit dem alten Eintrag und einem neuen: geht (Bestand wortgleich)
        $sp = $this->contracts->get('gas', (string)$c['id'])['special_payments'];
        $sp[] = ['date' => '2024-12-31', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 9, 'note' => 'Guthaben'];
        $this->contracts->update('gas', (string)$c['id'], ['special_payments' => $sp]);
        self::assertSame(300, mb_strlen((string)$this->contracts->get('gas', (string)$c['id'])['special_payments'][0]['note']));
        // ein neuer langer Eintrag nicht
        $sp[] = ['date' => '2025-01-31', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 1, 'note' => str_repeat('n', 121)];
        self::assertSame('special_payments.note', $this->tooLong(fn() => $this->contracts->update('gas', (string)$c['id'], ['special_payments' => $sp]))->params['field']);
    }

    public function testTheBackupImportReportsFieldAndLimitAndImportsNothing(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        $mid = (string)$this->meters->list('gas')[0]['id'];
        $this->readings->create('gas', ['meter_id' => $mid, 'date' => '2025-01-01', 'counter' => 1]);
        $dump = $backup->export(false);
        $dump['utilities']['gas']['meters'][0]['notes'] = str_repeat('m', 2001);
        $dump['utilities']['gas']['readings'][0]['note'] = str_repeat('r', 2001);
        $dump['reminders'] = [['id' => 'rem_1', 'title' => str_repeat('t', 301), 'next_due' => '2026-01-01']];
        $dump['settings']['location_name'] = str_repeat('o', 121);
        $dump['people'] = ['last_no' => 1, 'people' => [['id' => 'u_1', 'name' => str_repeat('p', 61)]]];
        try {
            $backup->import($dump, true);
            self::fail('zu lange Texte eingespielt');
        } catch (BackupInvalidException $e) {
            $found = [];
            foreach ($e->problems as $p) if (str_starts_with($p['problem'], 'too_long:')) $found[$p['pot'] . ' ' . $p['field']] = $p['max'];
            ksort($found);
            self::assertSame(['gas/meters notes' => 2000, 'gas/readings note' => 2000, 'people name' => 60,
                              'reminders title' => 300, 'settings location_name' => 120], $found);
        }
        self::assertSame('', (string)$this->meters->get('gas', $mid)['notes']);
    }

    public function testTheSampleDataStaysWithinTheLimits(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        $demo = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/demo-data/energietracker-demo-backup.json'), true);
        $report = $backup->import($demo, true);
        self::assertSame([], $report['problems']);
        foreach (glob(dirname(__DIR__, 3) . '/demo-data/personas/*.json') ?: [] as $f) {
            $p = json_decode((string)file_get_contents($f), true);
            if (!isset($p['backup_version'])) continue;
            self::assertSame([], $backup->import($p, true)['problems'], basename($f));
        }
    }

    public function testTheCsvImportReportsTheLineAlsoInADryRun(): void
    {
        $mid = (string)$this->meters->list('gas')[0]['id'];
        $import = new ReadingImportService($this->readings, $this->meters, $this->i18n);
        $csv = "Datum;Zählerstand;Notiz\n2025-01-01;100;kurz\n2025-02-01;150;" . str_repeat('x', 2001) . "\n";
        $dry = $import->importCsv('gas', $mid, $csv, true);
        self::assertCount(1, $dry['rows']);
        self::assertSame(1, $dry['skipped']);
        self::assertStringContainsString('3', $dry['errors'][0]);
        self::assertStringContainsString('2000', $dry['errors'][0]);
        $real = $import->importCsv('gas', $mid, $csv);
        self::assertSame(1, $real['imported']);
        self::assertSame(['kurz'], array_column($this->readings->list('gas', $mid), 'note'), 'nichts gekürzt übernommen');
        // ohne CSV (evcc, Portale): derselbe Schutz in importRows
        $rows = $import->importRows('gas', $mid, [['date' => '2025-03-01', 'counter' => 200, 'note' => str_repeat('y', 2001)]]);
        self::assertSame([0, 1], [$rows['imported'], $rows['skipped']]);
        self::assertStringContainsString('2000', $rows['errors'][0]);
    }

    public function testThePeriodCsvImportReportsTheLine(): void
    {
        $this->meters->update('wasser', (string)$this->meters->list('wasser')[0]['id'], ['capture' => 'period']);
        $wid = (string)$this->meters->list('wasser')[0]['id'];
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $csv = "Monat;Verbrauch;Notiz\n01.2025;3;ok\n02.2025;4;" . str_repeat('x', 2001) . "\n";
        $dry = $periods->importCsv('wasser', $wid, $csv, true);
        self::assertSame([1, 1], [$dry['would_import'], $dry['skipped']]);
        self::assertStringContainsString('2000', $dry['errors'][0]);
    }

    /** Die KI schreibt über dieselben Dienste; ihr Schema nennt die Grenze (enger bei der Notiz, Entscheidung v3.12.0). */
    public function testTheAiWritePathUsesTheList(): void
    {
        self::assertSame(TextLimits::MCP_NOTE, WriteTools::NOTE_MAX);
        self::assertLessThanOrEqual(TextLimits::of('readings', 'note'), WriteTools::NOTE_MAX);
    }

    /**
     * Texte, die die App selbst einträgt (Notiz „aus dem Posteingang: {name}“, Termin „Dokument „{name}“ erledigen“),
     * passen mit dem längsten Dokumentnamen (NAME, AttachmentService) in jeder Sprache in ihre Grenze.
     */
    public function testAppWrittenTextsFitWithTheLongestDocumentName(): void
    {
        $name = str_repeat('N', TextLimits::NAME);
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $lang) {
            $cat = json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/public/locales/$lang.json"), true);
            $note = strtr((string)$cat['inbox']['applied']['note'], ['{name}' => $name]);
            self::assertLessThanOrEqual(TextLimits::of('readings', 'note'), mb_strlen($note), "$lang Notiz");
            self::assertLessThanOrEqual(TextLimits::of('reminders', 'notes'), mb_strlen($note), "$lang Notiz am Termin");
            // Namen (Dokument, Anbieter, Zähler) bis NAME, Tage in der längsten Schreibweise
            foreach ((array)$cat['inbox']['reminder'] as $k => $tpl) {
                if (!is_string($tpl)) continue;
                $title = strtr($tpl, ['{name}' => $name, '{provider}' => str_repeat('P', TextLimits::COMPANY), '{meter}' => $name]);
                $title = (string)preg_replace('/\{[a-z]+\}/', '31.12.2026', $title);
                self::assertLessThanOrEqual(TextLimits::of('reminders', 'title'), mb_strlen($title), "$lang inbox.reminder.$k");
            }
        }
    }
}
