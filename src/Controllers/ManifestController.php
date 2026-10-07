<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Services\I18nService;

/**
 * v3.1.0 (Review I18N-30) — Web-App-Manifest in der Sprache des Geräts.
 *
 * Bis v3.0 war `manifest.webmanifest` fest deutsch: Wer die App auf einem
 * englischen iPhone zum Home-Bildschirm hinzufügte, bekam „Zählerstände
 * erfassen“ als Kurzbefehl. `GET /api/manifest?lang=…` liefert dasselbe
 * Manifest mit Beschreibung und Kurzbefehlen aus dem Katalog.
 *
 * Öffentlich wie /api/health: Browser holen das Manifest ohne Cookies — mit
 * eingeschalteter Anmeldung ließe sich die App sonst nicht installieren. Es
 * enthält keine Daten.
 *
 * Die Pfade sind relativ zur Adresse des Manifests (`…/api.php/api/manifest`),
 * daher `../../`. Relativ statt absolut, damit es auch unter einem vorgeschalteten
 * Pfad funktioniert (Unterverzeichnis, Home-Assistant-Ingress). `id` löst gegen
 * `start_url` auf und bleibt `./` — eine installierte App bleibt dieselbe.
 * Die statische `manifest.webmanifest` bleibt für die öffentliche Demo.
 */
final class ManifestController
{
    public function __construct(private I18nService $i18n) {}

    public function show(Request $req): never
    {
        $lang = $this->i18n->normalize($req->queryParam('lang')) ?? $this->i18n->locale();
        $t = fn(string $key) => $this->i18n->t($key, [], $lang);
        $icon = fn(string $file, string $size, string $purpose = 'any') =>
            ['src' => "../../public/img/$file", 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose];
        $manifest = [
            'id'               => './',
            'name'             => 'Energietracker',
            'short_name'       => 'Energietracker',
            'description'      => $t('manifest.description'),
            'lang'             => $lang,
            'dir'              => 'ltr',
            'start_url'        => '../../',
            'scope'            => '../../',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#080c12',
            'theme_color'      => '#111827',
            'icons' => [
                $icon('icon-light-192.png', '192x192'),
                $icon('icon-light-512.png', '512x512'),
                $icon('icon-maskable-192.png', '192x192', 'maskable'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
            ],
            'shortcuts' => [
                ['name' => $t('manifest.shortcutCapture'), 'short_name' => $t('nav.captureButton'),
                 'url' => '../../#/zaehlerstaende', 'icons' => [array_diff_key($icon('icon-light-192.png', '192x192'), ['purpose' => 1])]],
                ['name' => $t('nav.contracts'), 'short_name' => $t('manifest.shortcutContractsShort'),
                 'url' => '../../#/contracts', 'icons' => [array_diff_key($icon('icon-light-192.png', '192x192'), ['purpose' => 1])]],
            ],
        ];
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/manifest+json; charset=utf-8');
            header('Cache-Control: no-cache');
        }
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
