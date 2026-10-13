<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DocsService;
use Energietracker\Services\I18nService;

/**
 * v3.4.0 (N1019) — Hilfe auf dem Server: Seiten und Bilder der Doku dieses
 * Release, nur über die Freigabeliste von DocsService. Anmeldung wie jede
 * andere Route (nicht öffentlich).
 */
final class DocsController
{
    public function __construct(
        private DocsService $docs,
        private I18nService $i18n,
    ) {}

    /** `GET /api/docs?path=<pfad>` → `{path, lang, mirror, root_files, markdown}` */
    public function show(Request $req): never
    {
        $path = (string)$req->queryParam('path', '');
        $page = $this->docs->page($path);
        if ($page === null) $this->notFound($path);
        Response::json($page);
    }

    /**
     * `GET /api/docs/asset?path=<pfad>&v=<Version>` → das Bild. Mit der
     * Version dieses Release in der Adresse ändert es sich nie (immutable);
     * ohne oder mit einer anderen Version fragt der Browser jedes Mal nach.
     */
    public function asset(Request $req): never
    {
        $asset = $this->docs->asset((string)$req->queryParam('path', ''));
        if ($asset === null) $this->notFound(null);
        $current = $req->queryParam('v') === $this->docs->version();
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: ' . $asset['type']);
            header('Content-Length: ' . (string)filesize($asset['file']));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: ' . ($current ? 'private, max-age=31536000, immutable' : 'private, no-cache'));
        }
        readfile($asset['file']);
        exit;
    }

    /** 404 — bei einem alten Pfad mit dem neuen in `detail.moved_to`. */
    private function notFound(?string $path): never
    {
        $moved = $path !== null ? $this->docs->movedTo($path) : null;
        Response::error($this->i18n->t('errors.docs.notFound'), 404,
            $moved !== null ? ['moved_to' => $moved] : null, 'errors.docs.notFound', true);
    }
}
