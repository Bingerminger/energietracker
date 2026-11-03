<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.10.0 — Lieferkette des Images: Signatur (cosign ohne Schlüssel) beim
 * Release und beim Wochen-Neubau, Scan direkt nach dem Release mit nur
 * behebbaren Befunden, Fristen und Prüfanleitung in SECURITY.md und Doku.
 * Herkunftsnachweis und SBOM gibt es seit v2.6.0 (BuildKit) — sie bleiben.
 *
 * Ob die Signatur auf GHCR wirklich entsteht, zeigt erst der Release-Lauf;
 * hier steht, dass Workflows, Doku und Richtlinie zueinander passen.
 */
final class ImageSupplyChainTest extends TestCase
{
    private const WORKFLOWS = ['.github/workflows/docker-publish.yml', '.github/workflows/docker-rebuild.yml'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function file(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** Text eines Schritts (bis zum nächsten `- name:` derselben Ebene). */
    private static function step(string $wf, string $name): string
    {
        self::assertSame(1, preg_match('/^(\s+)- name: ' . preg_quote($name, '/') . '\n(.*?)(?=^\1- name: |^  \S|\z)/ms', $wf, $m), "Schritt „{$name}“ fehlt");
        return $m[2];
    }

    public function testEveryPublishedImageIsSignedByDigest(): void
    {
        foreach (self::WORKFLOWS as $rel) {
            $wf = self::file($rel);
            self::assertMatchesRegularExpression('/^\s+id-token: write$/m', $wf, "$rel: OIDC-Token für keyless");
            self::assertMatchesRegularExpression('/uses: sigstore\/cosign-installer@[0-9a-f]{40} # v\d+\.\d+\.\d+$/m', $wf, $rel);
            // jede Variante baut mit eigener id, beide mit Herkunftsnachweis und SBOM (nicht doppeln, nicht verlieren)
            self::assertSame(1, preg_match_all('/^\s+id: build$/m', $wf), "$rel: id build");
            self::assertSame(1, preg_match_all('/^\s+id: build-rootless$/m', $wf), "$rel: id build-rootless");
            self::assertSame(2, substr_count($wf, 'provenance: true'), $rel);
            self::assertSame(2, substr_count($wf, 'sbom: true'), $rel);
            self::assertStringNotContainsString('attest-build-provenance', $wf, "$rel: Herkunftsnachweis gibt es schon (BuildKit)");

            preg_match('/^(\s+)- name: (Signieren[^\n]*)$/m', $wf, $n);
            self::assertNotEmpty($n, "$rel: Signierschritt fehlt");
            $sign = self::step($wf, $n[2]);
            self::assertStringContainsString('DIGEST: ${{ steps.build.outputs.digest }}', $sign, $rel);
            self::assertStringContainsString('DIGEST_ROOTLESS: ${{ steps.build-rootless.outputs.digest }}', $sign, $rel);
            self::assertStringContainsString('IMAGE: ghcr.io/bingerminger/energietracker', $sign, $rel);
            self::assertStringContainsString('cosign sign --yes "$IMAGE@$DIGEST"', $sign, "$rel: Standard per Digest");
            self::assertStringContainsString('cosign sign --yes "$IMAGE@$DIGEST_ROOTLESS"', $sign, "$rel: ohne root per Digest");
            self::assertStringNotContainsString('--key', $sign, "$rel: ohne Schlüssel");
            self::assertStringNotContainsString('${{', (string)strstr($sign, 'run:'), "$rel: Ausdrücke nur über env, nicht im Skript");
            self::assertGreaterThan(strpos($wf, 'id: build-rootless'), strpos($wf, '- name: ' . $n[2]), "$rel: signiert nach beiden Bauschritten");
        }
    }

    public function testScansReportOnlyFixableFindingsRightAfterTheRelease(): void
    {
        $categories = [];
        foreach (self::WORKFLOWS as $rel) {
            $wf = self::file($rel);
            self::assertSame(1, preg_match('/^  scan:\n(.*?)(?=^  \S|\z)/ms', $wf, $job), "$rel: Scan-Job fehlt");
            $scan = $job[1];
            self::assertMatchesRegularExpression('/^    needs: (publish|rebuild)$/m', $scan, "$rel: Scan nach dem Bau");
            self::assertStringContainsString('continue-on-error: true', $scan, "$rel: nicht blockierend");
            self::assertStringContainsString('security-events: write', $scan, $rel);
            self::assertMatchesRegularExpression('/uses: anchore\/scan-action@[0-9a-f]{40} # v\d+\.\d+\.\d+$/m', $scan, $rel);
            self::assertMatchesRegularExpression('/^\s+only-fixed: true$/m', $scan, "$rel: nur Befunde mit verfügbarem Fix");
            self::assertMatchesRegularExpression('/^\s+fail-build: false$/m', $scan, $rel);
            self::assertStringContainsString('upload-sarif', $scan, "$rel: → Security → Code scanning");
            preg_match_all('/^\s+category: (container-image\S*)$/m', $scan, $c);
            $categories[$rel] = $c[1];
        }
        self::assertSame(['container-image', 'container-image-rootless'], $categories[self::WORKFLOWS[0]]);
        self::assertSame($categories[self::WORKFLOWS[0]], $categories[self::WORKFLOWS[1]],
            'Release und Wochen-Neubau melden in dieselben Kategorien — der jüngste Lauf gilt');
        // Der Release-Scan prüft genau das signierte Image: per Digest des Bauschritts
        self::assertStringContainsString('energietracker@${{ matrix.variant == \'standard\' && needs.publish.outputs.digest || needs.publish.outputs.digest-rootless }}',
            self::file(self::WORKFLOWS[0]));
        self::assertMatchesRegularExpression('/^\s+digest: \$\{\{ steps\.build\.outputs\.digest \}\}$/m', self::file(self::WORKFLOWS[0]));
        self::assertMatchesRegularExpression('/^\s+digest-rootless: \$\{\{ steps\.build-rootless\.outputs\.digest \}\}$/m', self::file(self::WORKFLOWS[0]));
    }

    /**
     * Der Prüfbefehl in Doku und SECURITY.md trifft die Identitäten, mit denen die
     * Workflows signieren: Release vom Tag, Wochen-Neubau vom Standardzweig.
     */
    public function testVerifyCommandMatchesTheSigningWorkflows(): void
    {
        $identities = [
            'https://github.com/Bingerminger/energietracker/.github/workflows/docker-publish.yml@refs/tags/v3.10.0',
            'https://github.com/Bingerminger/energietracker/.github/workflows/docker-rebuild.yml@refs/heads/main',
        ];
        $foreign = [
            'https://github.com/someone/energietracker/.github/workflows/docker-publish.yml@refs/tags/v3.10.0',
            'https://github.com/Bingerminger/energietracker/.github/workflows/ci.yml@refs/heads/main',
        ];
        foreach (['docs/betrieb/docker.md', 'docs/en/betrieb/docker.md', 'SECURITY.md'] as $rel) {
            $md = self::file($rel);
            self::assertGreaterThanOrEqual(1, preg_match_all("/--certificate-identity-regexp '([^']+)'/", $md, $m), "$rel: Prüfbefehl fehlt");
            foreach ($m[1] as $re) {
                $pcre = '#' . str_replace('#', '\#', $re) . '#';
                foreach ($identities as $id) self::assertSame(1, preg_match($pcre, $id), "$rel: $re trifft $id nicht");
                foreach ($foreign as $id) self::assertSame(0, preg_match($pcre, $id), "$rel: $re trifft fremdes $id");
            }
            self::assertStringContainsString('--certificate-oidc-issuer https://token.actions.githubusercontent.com', $md, $rel);
            self::assertStringContainsString('cosign verify ghcr.io/bingerminger/energietracker:', $md, $rel);
            self::assertStringContainsString("--format '{{ json .SBOM }}'", $md, "$rel: SBOM ansehen");
            self::assertStringContainsString("--format '{{ json .Provenance }}'", $md, "$rel: Herkunftsnachweis ansehen");
        }
        foreach (self::WORKFLOWS as $rel) self::assertFileExists(self::root() . '/' . $rel);
        // Abschnitt, auf den SECURITY.md verweist
        self::assertMatchesRegularExpression('/^## Signatur und Stückliste prüfen \*\(v3\.10\.0\)\*$/m', self::file('docs/betrieb/docker.md'));
        self::assertMatchesRegularExpression('/^## Checking signature and SBOM \*\(v3\.10\.0\)\*$/m', self::file('docs/en/betrieb/docker.md'));
    }

    /** SECURITY.md: Frist für das Image, Meldeweg — in beiden Sprachen. */
    public function testSecurityPolicyNamesTheDeadline(): void
    {
        $md = self::file('SECURITY.md');
        [$en, $de] = explode("\n## Deutsch\n", $md, 2) + [1 => ''];
        self::assertNotSame('', $de, 'deutscher Teil fehlt');
        self::assertMatchesRegularExpression('/Critical or high finding with an available fix:\*\*[^.]*within \*\*7 days\*\*/', $en);
        self::assertMatchesRegularExpression('/Kritischer oder hoher Befund mit verfügbarer Korrektur:\*\*[^.]*binnen \*\*7 Tagen\*\*/', $de);
        foreach ([$en, $de] as $part) {
            self::assertStringContainsString('security/advisories/new', $part, 'vertraulich melden');
            self::assertStringContainsString('Grype', $part);
        }
        // v3.10.0 (Nachtrag) — Eingangsbestätigung binnen 7 Tagen; bei kritisch/hoch mit Korrektur eine neue
        // Patch-Version der Home-Assistant-App (bis dahin kam der Neubau erst mit dem nächsten Update der App)
        self::assertMatchesRegularExpression('/acknowledgement of receipt within \*\*7 days\*\*/', $en);
        self::assertMatchesRegularExpression('/binnen \*\*7 Tagen\*\* eine Eingangsbestätigung/', $de);
        self::assertMatchesRegularExpression('/critical or high finding with a fix, the Home Assistant app also gets\s+a new patch version/', $en);
        self::assertMatchesRegularExpression('/kritischen oder hohen Befund mit Korrektur bekommt zusätzlich die\s+App in Home Assistant eine neue Patch-Version/', $de);
        self::assertStringNotContainsString('next app update', $en);
        self::assertStringNotContainsString('nächsten Update der App', $de);
        $flat = fn(string $rel) => (string)preg_replace('/\s+/u', ' ', self::file($rel));
        self::assertStringContainsString('Du bekommst binnen 7 Tagen eine Eingangsbestätigung', $flat('docs/betrieb/sicherheit.md'));
        self::assertStringContainsString('App in Home Assistant bekommt eine neue Patch-Version', $flat('docs/betrieb/sicherheit.md'));
        self::assertStringContainsString('acknowledgement of receipt within 7 days', $flat('docs/en/betrieb/sicherheit.md'));
        self::assertStringContainsString('Home Assistant app gets a new patch version', $flat('docs/en/betrieb/sicherheit.md'));
        // Die wöchentliche Frist, auf die sich „spätestens mit dem nächsten Neubau“ stützt
        self::assertMatchesRegularExpression("/cron: '\\d+ \\d+ \\* \\* \\d'/", self::file('.github/workflows/docker-rebuild.yml'), 'wöchentlich');
    }

    /** PHPUnit bleibt bei 11, solange PHP 8.2 die Mindestversion ist (PHPUnit 12 verlangt 8.3). */
    public function testDependabotKeepsPhpUnitOnItsMajorVersion(): void
    {
        $dep = self::file('.github/dependabot.yml');
        self::assertSame(1, preg_match('/package-ecosystem: "composer"\n(.*?)(?=\n  - package-ecosystem|\z)/s', $dep, $c));
        self::assertMatchesRegularExpression('/- dependency-name: "phpunit\/phpunit"\n\s+update-types: \["version-update:semver-major"\]/', $c[1]);
        $composer = json_decode(self::file('composer.json'), true);
        self::assertSame('>=8.2', $composer['require']['php'] ?? null);
        self::assertStringStartsWith('^11.', $composer['require-dev']['phpunit/phpunit'] ?? '');
        // dieselbe Version von docker/login-action überall (#50)
        $refs = [];
        foreach (glob(self::root() . '/.github/workflows/*.yml') ?: [] as $f) {
            preg_match_all('/uses: docker\/login-action@(\S+ # \S+)/', (string)file_get_contents($f), $m);
            foreach ($m[1] as $r) $refs[$r] = true;
        }
        self::assertCount(1, $refs, 'eine Version von docker/login-action: ' . implode(', ', array_keys($refs)));
    }

    /** Kein Python mehr: weder im Repo (init_data.py, requirements.txt) noch im Image. */
    public function testNoPythonLeft(): void
    {
        self::assertFileDoesNotExist(self::root() . '/scripts/init_data.py');
        self::assertFileDoesNotExist(self::root() . '/requirements.txt');
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS),
            // versteckte Ordner (VCS, lokale Werkzeuge) und Ungeprüftes außen vor
            fn(\SplFileInfo $f) => !str_starts_with($f->getFilename(), '.')
                && !in_array($f->getFilename(), ['vendor', 'node_modules', 'data', 'samples', 'graphify-out', 'dist'], true)));
        $py = [];
        foreach ($it as $f) if (str_ends_with($f->getFilename(), '.py')) $py[] = $f->getPathname();
        self::assertSame([], $py);
        self::assertDoesNotMatchRegularExpression('/^RUN apk add[^\n]*\b(python3?|py3-|supervisor)\b/m', self::file('Dockerfile'));
        foreach (['docs/betrieb/installation.md', 'docs/en/betrieb/installation.md', 'docs/entwicklung/architektur.md', 'docs/en/entwicklung/architektur.md'] as $rel) {
            self::assertStringNotContainsString('init_data', self::file($rel), $rel);
        }
    }
}
