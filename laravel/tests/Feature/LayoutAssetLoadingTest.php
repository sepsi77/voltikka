<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use ReflectionProperty;
use Tests\TestCase;

class LayoutAssetLoadingTest extends TestCase
{
    use RefreshDatabase;

    private bool $buildDirectoryExisted;

    private string $manifestPath;

    private string|false $originalManifest;

    protected function setUp(): void
    {
        parent::setUp();

        $buildDirectory = public_path('build');
        $this->buildDirectoryExisted = is_dir($buildDirectory);
        $this->manifestPath = $buildDirectory.'/manifest.json';
        $this->originalManifest = is_file($this->manifestPath)
            ? file_get_contents($this->manifestPath)
            : false;

        if (! $this->buildDirectoryExisted) {
            mkdir($buildDirectory, recursive: true);
        }

        file_put_contents($this->manifestPath, json_encode([
            'resources/css/app.css' => [
                'file' => 'assets/app-layout-test.css',
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
            'resources/js/app.js' => [
                'file' => 'assets/app-layout-test.js',
                'src' => 'resources/js/app.js',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR));
        $this->resetViteManifestCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalManifest === false) {
            @unlink($this->manifestPath);
        } else {
            file_put_contents($this->manifestPath, $this->originalManifest);
        }

        if (! $this->buildDirectoryExisted) {
            @rmdir(dirname($this->manifestPath));
        }

        $this->resetViteManifestCache();

        parent::tearDown();
    }

    public function test_vite_assets_use_framework_generated_loading_tags(): void
    {
        $html = $this->get('/tietosuoja')->assertOk()->getContent();
        $cssUrl = asset('build/assets/app-layout-test.css');
        $jsUrl = asset('build/assets/app-layout-test.js');

        $this->assertSame(2, substr_count($html, $cssUrl));
        $this->assertStringContainsString('<link rel="preload" as="style" href="'.$cssUrl.'" />', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="'.$cssUrl.'"', $html);

        $this->assertSame(2, substr_count($html, $jsUrl));
        $this->assertStringContainsString('<link rel="modulepreload" href="'.$jsUrl.'" />', $html);
        $this->assertStringContainsString('<script type="module" src="'.$jsUrl.'"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '~<link\b(?=[^>]*\brel="preload")(?=[^>]*\bhref="'.preg_quote($jsUrl, '~').'")(?=[^>]*\bas="script")[^>]*>~i',
            $html
        );
    }

    public function test_livewire_uses_its_versioned_script_without_a_stale_preload(): void
    {
        $html = $this->get('/tietosuoja')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '~<link\b[^>]*\bhref="/vendor/livewire/livewire\.min\.js"[^>]*>~i',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<script\s+src="https?://[^/]+/vendor/livewire/livewire(?:\.min)?\.js\?id=[a-z0-9]+"~i',
            $html
        );
    }

    private function resetViteManifestCache(): void
    {
        $manifests = new ReflectionProperty(Vite::class, 'manifests');
        $manifests->setValue(null, []);
    }
}
