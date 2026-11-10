<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\Mcp\Resources;
use Energietracker\Services\Mcp\Server;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 (F1037, Paket doku-vorlagen) — Ressourcen (Konzept §3.5): Glossar
 * und Herkunft in der Sprache des Servers, jede Seite der Freigabeliste über
 * die Vorlage (nie ein Pfad daneben), Originale aus dem Posteingang nur mit
 * Schalter und bis zur Grenze.
 */
final class McpResourcesTest extends McpTestCase
{
    private ?InboxService $inbox = null;
    private ?AttachmentService $attachments = null;

    protected function buildService(string $class): ?object
    {
        if ($class === AttachmentService::class) return $this->attachments ??= new AttachmentService($this->store, $this->settings, $this->i18n);
        if ($class === InboxService::class) {
            $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
            $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
            return $this->inbox ??= new InboxService($this->store, $this->i18n, $this->buildService(AttachmentService::class),
                new ProviderRegistry($this->settings, InboxFixture::provider()),
                new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings),
                new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        }
        return null;
    }

    private static function repo(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
    }

    /** @return array<string,mixed> der einzige Inhalt einer gelesenen Ressource */
    private function read(string $uri, string $locale = 'de'): array
    {
        $r = $this->rpc('resources/read', ['uri' => $uri], $this->context(locale: $locale));
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('result', $r['body'], "$uri: " . json_encode($r['body']['error'] ?? null));
        self::assertCount(1, $r['body']['result']['contents']);
        return $r['body']['result']['contents'][0];
    }

    /** @return array{0:int, 1:string} Code und Schlüssel des Fehlers */
    private function readError(string $uri): array
    {
        $r = $this->rpc('resources/read', ['uri' => $uri]);
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('error', $r['body'], "$uri wurde ausgeliefert");
        return [$r['body']['error']['code'], $r['body']['error']['data']['code']];
    }

    private function sharePdfs(bool $on): void
    {
        $this->settings->set(['mcp_share_documents' => $on]);
    }

    public function testListNamesGlossaryAndProvenance(): void
    {
        $list = $this->rpc('resources/list')['body']['result']['resources'];
        self::assertSame(['energietracker://hilfe/glossar', 'energietracker://hilfe/herkunft'], array_column($list, 'uri'));
        self::assertSame(['Glossar und Formeln', 'Woher eine Zahl kommt'], array_column($list, 'title'));
        self::assertSame(['text/markdown', 'text/markdown'], array_column($list, 'mimeType'));
        $en = $this->rpc('resources/list', [], $this->context(locale: 'en'))['body']['result']['resources'];
        self::assertSame(['Glossary and formulas', 'Where a figure comes from'], array_column($en, 'title'));
    }

    public function testFixedPagesFollowTheLanguageOfTheServer(): void
    {
        $de = $this->read('energietracker://hilfe/glossar');
        self::assertSame(['uri' => 'energietracker://hilfe/glossar', 'mimeType' => 'text/markdown',
            'text' => self::repo('docs/verstehen/09-glossar.md')], $de);
        self::assertSame(self::repo('docs/en/verstehen/09-glossar.md'), $this->read('energietracker://hilfe/glossar', 'en')['text']);
        self::assertSame(self::repo('docs/verstehen/18-herkunft.md'), $this->read('energietracker://hilfe/herkunft')['text']);
        self::assertSame(self::repo('docs/en/verstehen/18-herkunft.md'), $this->read('energietracker://hilfe/herkunft', 'en')['text']);
        // die festen Seiten gibt es (Resources::PAGES) in beiden Sprachen
        foreach (Resources::PAGES as $page) {
            self::assertFileExists(dirname(__DIR__, 4) . "/docs/$page");
            self::assertFileExists(dirname(__DIR__, 4) . "/docs/en/$page");
        }
    }

    public function testTemplateServesEveryPageOfTheAllowListAndNothingElse(): void
    {
        $tpl = $this->rpc('resources/templates/list')['body']['result']['resourceTemplates'];
        self::assertSame(['energietracker://hilfe/{path}'], array_column($tpl, 'uriTemplate'), 'ohne Freigabe keine Dokument-Vorlage');

        self::assertSame(self::repo('docs/verstehen/00-overview.md'), $this->read('energietracker://hilfe/verstehen/00-overview.md')['text']);
        self::assertSame(self::repo('docs/en/anleitungen/ki-assistent.md'), $this->read('energietracker://hilfe/en/anleitungen/ki-assistent.md')['text']);
        // kodierter Schrägstrich aus einer einfachen Vorlagen-Ersetzung
        self::assertSame(self::repo('docs/verstehen/00-overview.md'), $this->read('energietracker://hilfe/verstehen%2F00-overview.md')['text']);
        // Dateien im Wurzelverzeichnis aus der Freigabeliste; ohne .md als Text
        self::assertSame('text/markdown', $this->read('energietracker://hilfe/root/SECURITY.md')['mimeType']);
        self::assertSame('text/plain', $this->read('energietracker://hilfe/root/LICENSE')['mimeType']);

        foreach (['energietracker://hilfe/../src/bootstrap.php', 'energietracker://hilfe/%2e%2e/VERSION', 'energietracker://hilfe//etc/passwd',
                  'energietracker://hilfe/verstehen/../../VERSION', 'energietracker://hilfe/root/VERSION', 'energietracker://hilfe/root/.env',
                  'energietracker://hilfe/ERSTE-SCHRITTE.md', 'energietracker://hilfe/', 'energietracker://hilfe/glossar.md',
                  'energietracker://hilfe/ui/screenshots/dashboard.png', 'energietracker://Hilfe/glossar', 'file:///etc/passwd',
                  'energietracker://data/settings.json'] as $uri) {
            self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.unknownResource'], $this->readError($uri), $uri);
        }
    }

    public function testOriginalsOnlyWithTheSwitchAndUpToTheLimit(): void
    {
        $pdf = "%PDF-1.4\n% erfundenes Dokument\n%%EOF\n";
        $entry = $this->buildService(InboxService::class)->upload($pdf, 'gas.pdf');
        $uri = 'energietracker://dokument/' . $entry['id'] . '.pdf';

        // aus: weder Vorlage noch Inhalt, und die Meldung sagt, welcher Schalter fehlt
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.documentsNotShared'], $this->readError($uri));
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.documentsNotShared'], $this->readError('energietracker://dokument/inb_000000000000.pdf'));

        $this->sharePdfs(true);
        $tpl = $this->rpc('resources/templates/list')['body']['result']['resourceTemplates'];
        self::assertSame(['energietracker://hilfe/{path}', 'energietracker://dokument/{id}.pdf'], array_column($tpl, 'uriTemplate'));
        self::assertStringContainsString('5 MB', $tpl[1]['description']);
        self::assertSame(['uri' => $uri, 'mimeType' => 'application/pdf', 'blob' => base64_encode($pdf)], $this->read($uri));

        foreach (['energietracker://dokument/inb_000000000000.pdf', 'energietracker://dokument/' . $entry['id'],
                  'energietracker://dokument/' . $entry['id'] . '.pdf/../x', 'energietracker://dokument/' . $entry['attachment_id'] . '.pdf',
                  'energietracker://dokument/INB_' . substr($entry['id'], 4) . '.pdf'] as $bad) {
            self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.unknownResource'], $this->readError($bad), $bad);
        }

        // über der Grenze: Hinweis statt eines riesigen Blobs
        $big = $this->buildService(InboxService::class)->upload("%PDF-1.4\n" . str_repeat('0', Resources::DOCUMENT_MAX_BYTES), 'gross.pdf');
        $r = $this->rpc('resources/read', ['uri' => 'energietracker://dokument/' . $big['id'] . '.pdf']);
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.documentTooLarge'], [$r['body']['error']['code'], $r['body']['error']['data']['code']]);
        self::assertStringContainsString('5 MB', $r['body']['error']['message']);
        self::assertSame(5 * 1048576, Resources::DOCUMENT_MAX_BYTES);

        $this->sharePdfs(false);
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.documentsNotShared'], $this->readError($uri), 'wieder aus');
    }
}
