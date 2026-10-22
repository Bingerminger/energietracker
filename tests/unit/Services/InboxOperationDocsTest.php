<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\AuthService;
use Energietracker\Services\Inbox\FolderIntake;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\ReportAdviceService;
use PHPUnit\Framework\TestCase;

/**
 * v3.7.0 (Paket betrieb) — was die Anleitung „Posteingang“ über Kernaussagen,
 * Ordner und Schlüssel verspricht, hängt an den Konstanten, die wirklich
 * greifen (Lehre 31: Doku-Behauptungen brauchen einen Test). Deutsch und
 * Englisch.
 */
final class InboxOperationDocsTest extends TestCase
{
    private static function doc(string $lang): string
    {
        $root = dirname(__DIR__, 3);
        return (string)file_get_contents($lang === 'de' ? "$root/docs/anleitungen/posteingang.md" : "$root/docs/en/anleitungen/posteingang.md");
    }

    public function testNumbersInTheGuideAreTheOnesTheCodeUses(): void
    {
        $mb = (int)(AttachmentService::PDF_MAX / 1048576);
        $claims = [
            'de' => [
                Proposals::FOLLOW_UP_LEAD_DAYS . ' Tage vor dem Lieferende',
                Gist::DUNNING_DAYS . ' Tage nach dem Eingang',
                'höchstens ' . Gist::READING_WINDOW_DAYS . ' Tage vor der Frist',
                'unter ' . (int)ReportAdviceService::ADVANCE_MIN_EUR . ' €',
                'Je Aufruf höchstens ' . FolderIntake::MAX_PER_RUN . ' Dateien',
                'jünger als ' . FolderIntake::SETTLE_SECONDS . ' Sekunden',
                "größer als $mb MB",
                '`' . FolderIntake::DIR . '/`', FolderIntake::DIR . '/' . FolderIntake::DONE . '/', FolderIntake::DIR . '/' . FolderIntake::FAILED . '/',
            ],
            'en' => [
                Proposals::FOLLOW_UP_LEAD_DAYS . ' days before the end of supply',
                Gist::DUNNING_DAYS . ' days after it arrived',
                'at most ' . Gist::READING_WINDOW_DAYS . ' days before the deadline',
                'below €' . (int)ReportAdviceService::ADVANCE_MIN_EUR,
                'At most ' . FolderIntake::MAX_PER_RUN . ' files per call',
                'younger than ' . FolderIntake::SETTLE_SECONDS . ' seconds',
                "larger than $mb MB",
                '`' . FolderIntake::DIR . '/`', FolderIntake::DIR . '/' . FolderIntake::DONE . '/', FolderIntake::DIR . '/' . FolderIntake::FAILED . '/',
            ],
        ];
        foreach ($claims as $lang => $list) {
            $text = (string)preg_replace('/\s+/u', ' ', self::doc($lang));
            foreach ($list as $c) self::assertStringContainsString($c, $text, "$lang: „{$c}“");
        }
    }

    public function testTheGuideNamesTheInboxKeyAndBothBodyForms(): void
    {
        self::assertContains('inbox', AuthService::API_KEY_SCOPES);
        foreach (['de' => '„nur Posteingang (PDF ablegen)“', 'en' => '“inbox only (file PDFs)”'] as $lang => $label) {
            $text = (string)preg_replace('/\s+/u', ' ', self::doc($lang));
            self::assertStringContainsString(str_replace(' ', ' ', $label), $text, "$lang: Name des Bereichs wie in der App");
            self::assertStringContainsString('"pdf_base64"', $text);
            self::assertStringContainsString('imap.fetch_part', $text);
            foreach (['inbox-shortcut', 'inbox-folder', 'inbox-email'] as $anchor) {
                self::assertStringContainsString("<a id=\"$anchor\"></a>", $text, "$lang: Sprungmarke $anchor (Link aus dem Posteingang)");
            }
        }
        $root = dirname(__DIR__, 3);
        foreach (['de' => '„nur Posteingang (PDF ablegen)“', 'en' => '“inbox only (file PDFs)”'] as $lang => $label) {
            $cat = json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true);
            $inner = mb_substr($label, 1, -1);
            self::assertSame($inner, $cat['settings']['security']['scopeInbox'], "$lang: Doku und Katalog nennen den Bereich gleich");
        }
    }
}
