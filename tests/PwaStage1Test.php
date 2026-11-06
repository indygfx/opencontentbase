<?php

declare(strict_types=1);

namespace Tests\Pwa;

use PHPUnit\Framework\TestCase;

/**
 * PWA stage 1 (install + offline reading): manifest, service worker,
 * offline page, registration in app.js, layout links, i18n keys.
 *
 * Note: the SW runtime behavior itself is NOT unit-testable here (it needs an
 * HTTPS host) - the manual Lighthouse/verification checklist lives in
 * README.md ("PWA"). These tests pin the static artifacts and their markers.
 */
final class PwaStage1Test extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
    }

    public function testManifestIsValidJsonWithRequiredFields(): void
    {
        $path = $this->base . '/public/manifest.webmanifest';
        $this->assertFileExists($path);
        $manifest = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($manifest, 'manifest must be valid JSON');
        $this->assertSame('OpenContentBase', $manifest['name']);
        $this->assertSame('OCB', $manifest['short_name']);
        $this->assertSame('/writing', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('en', $manifest['lang']);
        // brand colors come from the icon palette, not the blue web theme
        $this->assertSame('#16a34a', $manifest['theme_color']);
        $this->assertSame('#14532d', $manifest['background_color']);
    }

    public function testManifestDeclaresAllThreeIcons(): void
    {
        $manifest = json_decode((string)file_get_contents($this->base . '/public/manifest.webmanifest'), true);
        $this->assertIsArray($manifest['icons']);
        $this->assertCount(3, $manifest['icons']);
        $byPurpose = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertSame('image/png', $icon['type']);
            $this->assertFileExists($this->base . '/public' . $icon['src']);
            $byPurpose[$icon['purpose']][] = $icon['sizes'];
        }
        $this->assertContains('192x192', $byPurpose['any'] ?? []);
        $this->assertContains('512x512', $byPurpose['any'] ?? []);
        $this->assertSame(['512x512'], $byPurpose['maskable'] ?? null);
    }

    public function testLayoutLinksManifestThemeColorAndAppleTouchIcon(): void
    {
        $layout = (string)file_get_contents($this->base . '/templates/layout.php');
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $layout);
        $this->assertStringContainsString('<meta name="theme-color" content="#16a34a">', $layout);
        $this->assertStringContainsString('rel="apple-touch-icon"', $layout);
        // toast strings travel as translated body data attributes
        $this->assertStringContainsString('data-update-available=', $layout);
        $this->assertStringContainsString('data-reload-now=', $layout);
    }

    public function testServiceWorkerHasVersionAndAllStrategyMarkers(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertMatchesRegularExpression("/const VERSION = 'v\d+'/", $sw);
        // four route classes
        $this->assertStringContainsString("'/assets/'", $sw, 'static asset prefix');
        $this->assertStringContainsString('/manifest.webmanifest', $sw);
        $this->assertStringContainsString('stale-while-revalidate', $sw);
        $this->assertStringContainsString('network-first', $sw);
        $this->assertStringContainsString('network-only', $sw);
        $this->assertStringContainsString("'/writing'", $sw, 'writing module prefix');
        // non-GET never cached
        $this->assertStringContainsString("req.method !== 'GET'", $sw);
        // offline fallback + update flow
        $this->assertStringContainsString("'/offline.html'", $sw);
        $this->assertStringContainsString('skipWaiting', $sw);
        $this->assertStringContainsString('clients.claim', $sw);
        $this->assertStringContainsString('caches.delete', $sw, 'old version caches are deleted');
    }

    public function testOfflinePageContainsAllFourLanguagesAndRetry(): void
    {
        $path = $this->base . '/public/offline.html';
        $this->assertFileExists($path);
        $html = (string)file_get_contents($path);
        foreach (['en', 'de', 'es', 'fr'] as $lang) {
            $this->assertStringContainsString('data-lang="' . $lang . '"', $html, 'language block ' . $lang);
        }
        $this->assertStringContainsString('navigator.language', $html, 'client-side language selection');
        $this->assertStringContainsString('location.reload()', $html, 'retry button reloads');
        $this->assertStringContainsString('/assets/css/master.css', $html, 'styled via the cached master.css');
        $this->assertStringContainsString('editing requires a connection', $html, 'en message');
        $this->assertStringContainsString('Bearbeiten erfordert eine Verbindung', $html, 'de message');
        $this->assertStringContainsString('editar requiere conexión', $html, 'es message');
        $this->assertStringContainsString('la modification nécessite une connexion', $html, 'fr message');
    }

    public function testAppJsRegistersServiceWorkerOnlyInSecureContext(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString('window.isSecureContext', $js);
        $this->assertStringContainsString("'serviceWorker' in navigator", $js);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $js);
        $this->assertStringContainsString('updatefound', $js);
        $this->assertStringContainsString('controllerchange', $js);
        $this->assertStringContainsString('app-toast', $js);
        $this->assertStringContainsString('dataset.updateAvailable', $js);
        $this->assertStringContainsString('dataset.reloadNow', $js);
    }

    public function testUpdateToastKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $file = $this->base . '/lang/' . $code . '/core.php';
            $src = (string)file_get_contents($file);
            $this->assertStringContainsString("'update_available'", $src, $file);
            $this->assertStringContainsString("'reload_now'", $src, $file);
        }
    }

    public function testPwaIconsStillPresentWithExactSizes(): void
    {
        foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'icon-512-maskable.png' => 512] as $file => $size) {
            $path = $this->base . '/public/assets/icons/' . $file;
            $this->assertFileExists($path, $file);
            $info = getimagesize($path);
            $this->assertNotFalse($info, $file);
            $this->assertSame($size, $info[0], $file . ' width');
            $this->assertSame($size, $info[1], $file . ' height');
        }
    }

    public function testDocsCoverPwaStage1(): void
    {
        $readme = (string)file_get_contents($this->base . '/README.md');
        $this->assertStringContainsString('## PWA', $readme);
        $this->assertStringContainsString('isSecureContext', $readme, 'dev limitation documented');
        $this->assertStringContainsString('offline.html', $readme);
        $roadmap = (string)file_get_contents($this->base . '/ROADMAP.md');
        $this->assertStringContainsString('PWA stage 1', $roadmap);
    }
}
