<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\InboxDraftService;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — Entwurf des Haushalts aus allen
 * ausgelesenen Dokumenten des Posteingangs, und sein Übernehmen.
 *
 *   GET  /api/inbox/draft[?documents=inb_…,inb_…]
 *   POST /api/inbox/draft/apply  {items: [id…], confirm: {id: [feld…]},
 *                                 choose: {id: {feld: option}}, documents?: [id…]}
 *
 * Schreiben darf, wer auch ein einzelnes Dokument übernehmen darf (ein
 * Lese-Schlüssel nicht). Eine veraltete Auswahl lehnt der Dienst ab, bevor er
 * etwas schreibt (400, `errors.inbox.proposalStale`).
 */
final class InboxDraftController
{
    public function __construct(private InboxDraftService $draft) {}

    public function show(Request $req): never
    {
        Response::json($this->draft->draft(self::ids($req->queryParam('documents'))));
    }

    public function apply(Request $req): never
    {
        $items = $req->input('items', []);
        $confirm = $req->input('confirm', []);
        $choose = $req->input('choose', []);
        $docs = $req->input('documents');
        Response::json($this->draft->apply(
            is_array($items) ? array_values(array_map('strval', array_filter($items, 'is_scalar'))) : [],
            self::confirmMap($confirm),
            self::chooseMap($choose),
            is_array($docs) ? array_values(array_map('strval', array_filter($docs, 'is_scalar'))) : self::ids(is_string($docs) ? $docs : null),
        ));
    }

    /** @return list<string>|null */
    private static function ids(?string $csv): ?array
    {
        if ($csv === null || trim($csv) === '') return null;
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($s) => $s !== ''));
    }

    /** @return array<string,list<string>> */
    private static function confirmMap(mixed $in): array
    {
        $out = [];
        foreach (is_array($in) ? $in : [] as $id => $names) {
            $out[(string)$id] = is_array($names) ? array_values(array_map('strval', array_filter($names, 'is_scalar'))) : $names;
        }
        return $out;
    }

    /** @return array<string,array<string,int>> */
    private static function chooseMap(mixed $in): array
    {
        $out = [];
        foreach (is_array($in) ? $in : [] as $id => $picks) {
            if (!is_array($picks)) { $out[(string)$id] = $picks; continue; }
            foreach ($picks as $name => $i) $out[(string)$id][(string)$name] = is_int($i) || (is_string($i) && ctype_digit($i)) ? (int)$i : $i;
        }
        return $out;
    }
}
