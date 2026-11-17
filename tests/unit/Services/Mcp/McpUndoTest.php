<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\RecordChanges;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.13.0 (Paket F) — Rücknahme von Übernahmen der KI im Verlauf (bis v3.12 `409`): der Rückweg je Datensatz
 * (Services\RecordChanges) an Ablesung und Dokument, nur für den jüngsten Stand, mit den Einträgen, die die
 * Übernahme mit anlegte; ohne festgehaltenen Rückweg (v3.12) bleibt es bei `409`; ein Rückweg aus einem fremden
 * Backup schreibt nichts außerhalb der Töpfe.
 */
final class McpUndoTest extends McpInboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->gasHousehold();
        $this->setReadings('gas', 'm_gas_default', [
            ['date' => '2024-12-31', 'counter' => 12345.0, 'device_id' => 'd_gas_1'],
            ['date' => '2026-01-01', 'counter' => 13100.0, 'device_id' => 'd_gas_1'],
        ]);
    }

    /** @return string Verlaufseintrag der übernommenen Ablesung */
    private function reading(string $date, float $counter): string
    {
        $p = $this->callTool('propose_reading', ['utility' => 'gas', 'meter' => 'm_gas_default', 'date' => $date, 'counter' => $counter], $this->writer());
        return (string)$this->callTool('apply_proposal', ['proposal_id' => $p['proposal_id'], 'summary' => $p['summary']], $this->writer())['activity_ids'][0];
    }

    public function testAReadingOfTheAiIsUndoneLikeOtherEntries(): void
    {
        $before = $this->snapshot();
        $act = $this->reading('2026-02-01', 13200.0);
        $e = $this->activity->get($act);
        self::assertSame('records', $e['revert']['type']);
        self::assertSame(['gas/readings.json'], array_values(array_unique(array_column($e['revert']['changes'], 'file'))), 'nur die Ablesung');
        // „Zählerstand ablesen“ war fällig: die Übernahme hat es mit erledigt (`also`)
        self::assertNotSame([], $e['revert']['also'] ?? []);
        $u = $this->activity->undo($act);
        self::assertTrue($u['reverted']);
        self::assertSame(['by' => null], array_intersect_key($u['undone'], ['by' => 1]), 'ohne Anmeldung: niemand');
        foreach ($e['revert']['also'] as $other) self::assertNotEmpty($this->activity->get($other)['undone'] ?? null, 'mit durchgestrichen');
        self::assertNotContains('2026-02-01', array_column($this->readings->list('gas', 'm_gas_default'), 'date'));
        $changed = array_diff(self::changed($before, $this->snapshot()), ['activity.json']);
        self::assertSame([], array_values($changed), 'die Daten wie vor der Übernahme');
        // ein zweites Rückgängig ändert nichts mehr
        self::assertFalse($this->activity->undo($act)['reverted']);
    }

    public function testOnlyTheLatestDependentStateCanBeUndone(): void
    {
        // zwei Übernahmen am selben Vertrag: Erst die spätere zurück, dann die frühere
        $doc = function (string $tag, array $answer): string {
            $id = (string)$this->inbox->upload(InboxFixture::pdf($tag), "$tag.pdf")['id'];
            $this->callTool('read_document', ['document_id' => $id, 'type' => 'price_change', 'pages' => InboxFixture::priceChangePages(),
                'fields' => $answer], $this->writer());
            return $id;
        };
        $id1 = $doc('pc1', InboxFixture::priceChangeAnswer());
        $p1 = $this->callTool('propose_document', ['document_id' => $id1], $this->writer());
        $a1 = (string)$this->callTool('apply_proposal', ['proposal_id' => $p1['proposal_id'], 'summary' => $p1['summary']], $this->writer())['activity_ids'][0];
        $contractAfter1 = $this->contracts->list('gas')[0];
        // von Hand am selben Vertrag nachgebessert
        $this->contracts->update('gas', (string)$contractAfter1['id'], ['notes' => 'Preis geprüft']);
        try {
            $this->activity->undo($a1);
            self::fail('zurückgenommen trotz späterer Änderung');
        } catch (HttpStatusException $e) {
            self::assertSame([409, 'errors.activity.undoChanged'], [$e->status, $e->key]);
        }
        self::assertSame('Preis geprüft', $this->contracts->list('gas')[0]['notes'], 'nichts zurückgestellt');
        self::assertArrayNotHasKey('undone', $this->activity->get($a1));
        // die Hand-Änderung zurück: dann ist die Übernahme wieder der jüngste Stand
        $this->contracts->update('gas', (string)$contractAfter1['id'], ['notes' => (string)($contractAfter1['notes'] ?? '')]);
        $all = $this->store->read('gas/contracts.json', []);
        $all[0]['updated_at'] = $contractAfter1['updated_at'] ?? null;
        if ($all[0]['updated_at'] === null) unset($all[0]['updated_at']);
        $this->store->write('gas/contracts.json', $all);
        $u = $this->activity->undo($a1);
        self::assertTrue($u['reverted']);
        self::assertSame([11.0], array_map('floatval', array_column($this->contracts->list('gas')[0]['working_prices'], 'ct_per_kwh')), 'Preiszeile der Anpassung wieder weg');
        self::assertSame('read', $this->inbox->get($id1)['status'], 'das Dokument steht wieder zum Übernehmen bereit');
        self::assertNotEmpty($this->inbox->get($id1)['extraction']['fields'] ?? null, 'die Auslesung bleibt am Dokument');
    }

    /**
     * Was die Übernahme anlegte und worauf seitdem etwas verweist, nimmt die Rücknahme nicht weg: das Gerät eines
     * Zählertauschs mit einer späteren Ablesung darauf, ein angelegter Vertrag mit einer späteren Rechnung daran.
     * Die Dienste sperren solche Löschungen; restore() schriebe an ihnen vorbei.
     */
    public function testRecordsThatDependOnWhatTheApplyCreatedBlockTheUndo(): void
    {
        // Zählertausch: neues Gerät am bestehenden Zähler
        $before = RecordChanges::capture($this->store);
        $this->meters->replaceDevice('gas', 'm_gas_default', ['date' => '2026-01-15', 'old_final_counter' => 13150, 'new_initial_counter' => 0, 'serial' => 'NEU-1']);
        $swap = RecordChanges::diff($before, RecordChanges::capture($this->store));
        self::assertSame([], RecordChanges::conflicts($this->store, $swap), 'gleich danach: zurücknehmbar');
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2026-02-15', 'counter' => 40]);
        self::assertSame(['gas/readings.json'], array_values(array_unique(array_map(fn($x) => explode('#', $x)[0], RecordChanges::conflicts($this->store, $swap)))),
            'die spätere Ablesung hängt am neuen Gerät');

        // Vertrag angelegt, danach eine Rechnung an ihm
        $before = RecordChanges::capture($this->store);
        $c = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Neu', 'start' => '2027-01-01',
            'working_prices' => [['from' => '2027-01-01', 'ct_per_kwh' => 12.0]]]);
        $created = RecordChanges::diff($before, RecordChanges::capture($this->store));
        self::assertSame([], RecordChanges::conflicts($this->store, $created));
        $this->bills->create('gas', ['meter_id' => 'm_gas_default', 'period_from' => '2027-01-01', 'period_to' => '2027-12-31', 'contract_id' => (string)$c['id']]);
        self::assertNotSame([], RecordChanges::conflicts($this->store, $created), 'die Rechnung hängt am neuen Vertrag');
    }

    /** Ein Rückweg darf nur die Einstellung zurückstellen, die eine Übernahme schreibt (Gas-Umrechnung). */
    public function testAWayBackCannotChangeOtherSettings(): void
    {
        $act = $this->reading('2026-02-01', 13200.0);
        $all = $this->store->read('activity.json', []);
        foreach ($all as $i => $e) {
            if ($e['id'] !== $act) continue;
            $all[$i]['revert']['changes'][] = ['file' => 'settings.json', 'key' => 'mcp_share_documents', 'before' => ['v' => true], 'after' => null];
        }
        $this->store->write('activity.json', array_values($all));
        try {
            $this->activity->undo($act);
            self::fail('Verwalter-Einstellung über einen Rückweg');
        } catch (HttpStatusException $e) {
            self::assertSame('errors.activity.undoChanged', $e->key);
        }
        self::assertNotTrue($this->settings->get('mcp_share_documents', false));
    }

    public function testWithoutARecordedWayBackItStays409(): void
    {
        $act = $this->reading('2026-02-01', 13200.0);
        $all = $this->store->read('activity.json', []);
        foreach ($all as $i => $e) if ($e['id'] === $act) unset($all[$i]['revert']);   // wie ein Eintrag aus v3.12
        $this->store->write('activity.json', array_values($all));
        try {
            $this->activity->undo($act);
            self::fail('ohne Rückweg durchgestrichen');
        } catch (HttpStatusException $e) {
            self::assertSame([409, 'errors.activity.undoNotPossible'], [$e->status, $e->key]);
        }
        self::assertContains('2026-02-01', array_column($this->readings->list('gas', 'm_gas_default'), 'date'));
    }

    /** Ein Rückweg aus einem fremden Backup nennt eine Datei außerhalb der Töpfe: nichts wird geschrieben. */
    public function testAForeignWayBackWritesNothingOutsideThePots(): void
    {
        $act = $this->reading('2026-02-01', 13200.0);
        $all = $this->store->read('activity.json', []);
        foreach ($all as $i => $e) {
            if ($e['id'] !== $act) continue;
            $all[$i]['revert']['changes'][] = ['file' => 'auth.json', 'whole' => true, 'before' => ['x' => 1], 'after' => null];
        }
        $this->store->write('activity.json', array_values($all));
        self::assertNotSame([], RecordChanges::conflicts($this->store, $this->activity->get($act)['revert']['changes']));
        try {
            $this->activity->undo($act);
            self::fail('fremde Datei');
        } catch (HttpStatusException $e) {
            self::assertSame('errors.activity.undoChanged', $e->key);
        }
        self::assertFalse(is_file($this->dataDir . '/auth.json') && str_contains((string)file_get_contents($this->dataDir . '/auth.json'), '"x"'));
        self::assertContains('2026-02-01', array_column($this->readings->list('gas', 'm_gas_default'), 'date'));
    }
}
