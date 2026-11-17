<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Tests\Services\Mcp\McpReadTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Der eigene Export lässt sich unverändert wieder einspielen: an jedem Beispielhaushalt (Schaufenster und vier
 * Personas) und an beiden Testhaushalten (unordentlich, mit Verträgen, die nicht glatt laufen). Die Testhaushalte kommen
 * hier wie in ihren eigenen Tests direkt in die Dateien, nicht über den Import; der Trockenlauf des eigenen Exports
 * meldet trotzdem nichts, das Einspielen ändert nichts, und ein zweiter Export ist derselbe.
 */
#[CoversClass(BackupService::class)]
final class BackupRoundTripTest extends McpReadTestCase
{
    /** @return array<string, array{0:string}> */
    public static function households(): array
    {
        return ['Schaufenster' => ['demo'], 'Eigenheim klassisch' => ['eigenheim-klassisch'], 'Eigenheim modern' => ['eigenheim-modern'],
            'ETW Fernwärme' => ['etw-fernwaerme'], 'Mieterin' => ['mieterin'], 'unordentlicher Testhaushalt' => ['messy'],
            'Testhaushalt mit Verträgen, die nicht glatt laufen' => ['edge']];
    }

    #[DataProvider('households')]
    public function testTheOwnExportImportsUnchanged(string $id): void
    {
        match ($id) {
            'messy' => $this->messy(),
            'edge'  => $this->edge(),
            default => $this->persona($id),
        };
        $backup = new BackupService($this->store, $this->i18n);
        $first = $backup->export(true);
        try {
            $report = $backup->import($first, true);
        } catch (BackupInvalidException $e) {
            self::fail("$id: eigener Export abgewiesen: " . json_encode($e->problems, JSON_UNESCAPED_UNICODE));
        }
        self::assertSame([], $report['problems']);
        $backup->import($first);
        $second = $backup->export(true);
        unset($first['exported_at'], $second['exported_at']);
        foreach (array_keys($first) as $k) self::assertSame($first[$k], $second[$k], "$id: Topf $k nach der Rundreise anders");
        self::assertSame(array_keys($first), array_keys($second));
    }
}
