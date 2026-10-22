<?php
declare(strict_types=1);

/**
 * v3.7.0 (Haushalt aus Dokumenten) — für tests/inbox-draft.test.mjs: abgelegte
 * Dokumente so setzen, als wären sie ausgelesen (erfundene Auslesungen, echte
 * Belegprüfung). Ohne Composer: nur src/bootstrap.php und die beiden
 * Testhelfer.
 *
 *   php tests/fixtures/draft-seed.php <datenverzeichnis> <auftrag.json>
 *
 * Auftrag: [{"name": "rechnung.pdf", "type": "invoice", "values": {…}, "flag": ["consumption"]}, …]
 */

require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../unit/Support/InboxFixture.php';
require_once __DIR__ . '/../unit/Support/DraftFixture.php';

use Energietracker\Storage\JsonStore;
use Energietracker\Tests\Support\DraftFixture;

[$_, $dir, $spec] = $argv + [null, null, null];
if (!is_string($dir) || !is_dir($dir) || !is_string($spec) || !is_file($spec)) {
    fwrite(STDERR, "Aufruf: php draft-seed.php <datenverzeichnis> <auftrag.json>\n");
    exit(2);
}
$store = new JsonStore((string)realpath($dir));
$byName = array_column((array)$store->read('inbox.json', []), 'id', 'name');
foreach ((array)json_decode((string)file_get_contents($spec), true) as $doc) {
    $id = $byName[$doc['name']] ?? null;
    if ($id === null) { fwrite(STDERR, "nicht im Posteingang: {$doc['name']}\n"); exit(1); }
    DraftFixture::patch($store, (string)$id, (string)$doc['type'], (array)$doc['values'], (array)($doc['flag'] ?? []));
}
echo "ok\n";
