<?php
declare(strict_types=1);

/**
 * v3.7.0 (Posteingang) — Auslesung des Beispieldokuments für die Beispieldaten.
 *
 * tools/build-personas.mjs legt dem Schaufenster ein erfundenes Dokument samt
 * „vorberechneter Auslesung“ in den Posteingang (die öffentliche Demo kann
 * nicht auslesen). Die Antwort des Modells steht dort; geprüft wird sie hier
 * mit derselben Belegprüfung wie im Betrieb (Inbox\Evidence), damit die Demo
 * genau zeigt, was die App aus dieser Antwort machte.
 *
 *   echo '{"type":"invoice","pages":[…],"fields":{…}}' | php tools/inbox-sample.php
 *
 * Ausgabe: {"fields": {…geprüft…}, "flags": n, "schema_version": n}
 * — v3.7.0 (treffsicher, Nachbesserung 2): die Version des Schemas aus dem Code, nicht als Kopie im Generator.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;

$in = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($in) || !is_string($in['type'] ?? null) || !is_array($in['pages'] ?? null)) {
    fwrite(STDERR, "inbox-sample: {type, pages, fields} erwartet\n");
    exit(1);
}
$fields = Evidence::check($in['type'], (array)($in['fields'] ?? []), array_values($in['pages']));
echo json_encode(['fields' => $fields, 'flags' => Evidence::flagCount($fields), 'schema_version' => InboxSchema::VERSION], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
