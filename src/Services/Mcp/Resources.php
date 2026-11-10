<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\DocsService;
use Energietracker\Services\I18nService;
use Energietracker\Services\InboxService;

/**
 * v3.12.0 (F1037) — Ressourcen (Konzept §3.5): Lesestoff für die KI.
 *
 *  - `energietracker://hilfe/glossar`   Glossar & Formeln (Seite der Doku, DE oder EN)
 *  - `energietracker://hilfe/herkunft`  Woher eine Zahl kommt (Herkunft und Vorzeichen)
 *  - `energietracker://hilfe/{path}`    jede Seite aus der Freigabeliste von DocsService
 *                                       (`verstehen/00-overview.md`, `en/…`, `root/CHANGELOG.md`)
 *  - `energietracker://dokument/{id}.pdf` Original aus dem Posteingang, nur mit
 *                                       `mcp_share_documents`, bis DOCUMENT_MAX_BYTES
 *
 * Die Hilfe kommt nur über DocsService (reiner Nachschlag in der Freigabeliste,
 * kein Pfad wird mit einem Verzeichnis verkettet), das Original nur über
 * Posteingang und Belege (Kennung nach InboxService::ID). Ausgeschaltet nennt
 * `resources/templates/list` die Dokument-Vorlage nicht, und ein Abruf sagt,
 * welcher Schalter fehlt.
 */
class Resources
{
    public const SCHEME = 'energietracker://';
    public const HELP = self::SCHEME . 'hilfe/';
    public const DOCUMENT = self::SCHEME . 'dokument/';
    /** feste Ressourcen: Name → Seite der Doku (deutscher Schlüssel; Englisch unter `en/`) */
    public const PAGES = [
        'glossar'  => 'verstehen/09-glossar.md',
        'herkunft' => 'verstehen/18-herkunft.md',
    ];
    /** Original-PDF höchstens so groß (Base64 macht daraus gut ein Drittel mehr) */
    public const DOCUMENT_MAX_BYTES = 5 * 1048576;

    public function __construct(protected Services $services, protected I18nService $i18n) {}

    /**
     * Feste Ressourcen für `resources/list`: `{uri, name, title, description, mimeType}`.
     *
     * @return list<array<string,mixed>>
     */
    public function list(McpContext $ctx): array
    {
        $out = [];
        foreach (self::PAGES as $name => $page) {
            $out[] = [
                'uri'         => self::HELP . $name,
                'name'        => $name,
                'title'       => $this->t("mcp.resources.$name.title", $ctx),
                'description' => $this->t("mcp.resources.$name.description", $ctx),
                'mimeType'    => 'text/markdown',
            ];
        }
        return $out;
    }

    /**
     * Vorlagen für `resources/templates/list`: `{uriTemplate, name, title, description, mimeType}`.
     *
     * @return list<array<string,mixed>>
     */
    public function templates(McpContext $ctx): array
    {
        $out = [[
            'uriTemplate' => self::HELP . '{path}',
            'name'        => 'hilfe',
            'title'       => $this->t('mcp.resources.hilfe.title', $ctx),
            'description' => $this->t('mcp.resources.hilfe.description', $ctx),
            'mimeType'    => 'text/markdown',
        ]];
        if ($this->config()->shareDocuments()) {
            $out[] = [
                'uriTemplate' => self::DOCUMENT . '{id}.pdf',
                'name'        => 'dokument',
                'title'       => $this->t('mcp.resources.dokument.title', $ctx),
                'description' => $this->t('mcp.resources.dokument.description', $ctx, ['max' => intdiv(self::DOCUMENT_MAX_BYTES, 1048576)]),
                'mimeType'    => 'application/pdf',
            ];
        }
        return $out;
    }

    /**
     * Ergebnis von `resources/read`: `{contents: [{uri, mimeType, text|blob}]}`,
     * null bei unbekannter URI (der Server antwortet dann mit -32602).
     *
     * @return array<string,mixed>|null
     */
    public function read(string $uri, McpContext $ctx): ?array
    {
        if (str_starts_with($uri, self::HELP)) {
            $rest = substr($uri, strlen(self::HELP));
            if (isset(self::PAGES[$rest])) {
                $key = ($ctx->locale === 'de' ? '' : 'en/') . self::PAGES[$rest];
            } else {
                // `{path}` kommt je nach Client mit kodiertem Schrägstrich (%2F); nur nachgeschlagen
                $key = rawurldecode($rest);
            }
            $page = $this->docs()->page($key);
            if ($page === null) return null;
            return ['contents' => [[
                'uri'      => $uri,
                'mimeType' => str_ends_with($key, '.md') ? 'text/markdown' : 'text/plain',
                'text'     => $page['markdown'],
            ]]];
        }
        if (preg_match('#^' . preg_quote(self::DOCUMENT, '#') . '(inb_[0-9a-f]{12})\.pdf$#', $uri, $m)) {
            return $this->document($uri, $m[1]);
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function document(string $uri, string $id): ?array
    {
        if (!$this->config()->shareDocuments()) {
            throw new RpcError(200, Server::INVALID_PARAMS, 'errors.mcp.documentsNotShared');
        }
        if (!preg_match(InboxService::ID, $id)) return null;
        try {
            $entry = $this->services->get(InboxService::class)->get($id);
            $path = $this->services->get(AttachmentService::class)->path((string)($entry['attachment_id'] ?? ''));
        } catch (NotFoundException) {
            return null;
        }
        $size = (int)filesize($path);
        if ($size > self::DOCUMENT_MAX_BYTES) {
            throw new RpcError(200, Server::INVALID_PARAMS, 'errors.mcp.documentTooLarge', ['max' => intdiv(self::DOCUMENT_MAX_BYTES, 1048576)]);
        }
        $bytes = (string)file_get_contents($path);
        // Belege des Posteingangs sind PDF (InboxService::upload); alles andere geht nicht hinaus
        if (AttachmentService::sniff($bytes) !== 'application/pdf') return null;
        return ['contents' => [['uri' => $uri, 'mimeType' => 'application/pdf', 'blob' => base64_encode($bytes)]]];
    }

    private function config(): Config
    {
        return $this->services->get(Config::class);
    }

    /** Die Doku dieser Installation; in der App gibt es keinen gemeinsamen DocsService im Container. */
    private function docs(): DocsService
    {
        return $this->services->has(DocsService::class) ? $this->services->get(DocsService::class) : new DocsService(dirname(__DIR__, 3));
    }

    /** @param array<string,scalar> $params */
    private function t(string $key, McpContext $ctx, array $params = []): string
    {
        return $this->i18n->t($key, $params, $ctx->locale);
    }
}
