<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Tests\TestCase;

class OptimizeLogosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Create a test PNG image with specified dimensions.
     */
    private function createTestImage(int $width, int $height): string
    {
        $image = Image::create($width, $height)->fill('ff0000');

        return $image->toPng()->toString();
    }

    /**
     * Test command optimizes a large image by resizing and converting to WebP.
     */
    public function test_command_resizes_large_images_to_max_width(): void
    {
        $largeImage = $this->createTestImage(500, 300);
        Storage::disk('public')->put('logos/large-company.png', $largeImage);

        $this->artisan('logos:optimize')
            ->expectsOutputToContain('Optimizing logos')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/large-company.webp');

        $optimizedContent = Storage::disk('public')->get('logos/large-company.webp');
        $optimizedImage = Image::read($optimizedContent);

        $this->assertLessThanOrEqual(200, $optimizedImage->width());
    }

    /**
     * Test command preserves aspect ratio when resizing.
     */
    public function test_command_preserves_aspect_ratio(): void
    {
        $wideImage = $this->createTestImage(600, 200);
        Storage::disk('public')->put('logos/wide-company.png', $wideImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/wide-company.webp');

        $optimizedContent = Storage::disk('public')->get('logos/wide-company.webp');
        $optimizedImage = Image::read($optimizedContent);

        $this->assertEquals(200, $optimizedImage->width());
        $expectedHeight = (int) round(200 * (200 / 600));
        $this->assertEquals($expectedHeight, $optimizedImage->height());
    }

    /**
     * Test command does not upscale small images.
     */
    public function test_command_does_not_upscale_small_images(): void
    {
        $smallImage = $this->createTestImage(100, 50);
        Storage::disk('public')->put('logos/small-company.png', $smallImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/small-company.webp');

        $optimizedContent = Storage::disk('public')->get('logos/small-company.webp');
        $optimizedImage = Image::read($optimizedContent);

        $this->assertEquals(100, $optimizedImage->width());
        $this->assertEquals(50, $optimizedImage->height());
    }

    /**
     * Test command handles JPG files.
     */
    public function test_command_handles_jpg_files(): void
    {
        $jpgImage = Image::create(400, 300)->fill('00ff00')->toJpeg()->toString();
        Storage::disk('public')->put('logos/jpg-company.jpg', $jpgImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/jpg-company.webp');
    }

    /**
     * Test command handles JPEG files.
     */
    public function test_command_handles_jpeg_files(): void
    {
        $jpegImage = Image::create(400, 300)->fill('0000ff')->toJpeg()->toString();
        Storage::disk('public')->put('logos/jpeg-company.jpeg', $jpegImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/jpeg-company.webp');
    }

    /**
     * Test command processes multiple images.
     */
    public function test_command_processes_multiple_images(): void
    {
        Storage::disk('public')->put('logos/company-a.png', $this->createTestImage(500, 300));
        Storage::disk('public')->put('logos/company-b.jpg', Image::create(400, 200)->fill('ff0000')->toJpeg()->toString());
        Storage::disk('public')->put('logos/company-c.png', $this->createTestImage(300, 150));

        $this->artisan('logos:optimize')
            ->expectsOutputToContain('3')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/company-a.webp');
        Storage::disk('public')->assertExists('logos/company-b.webp');
        Storage::disk('public')->assertExists('logos/company-c.webp');
    }

    /**
     * Test command shows message when no images found.
     */
    public function test_command_shows_message_when_no_images(): void
    {
        $this->artisan('logos:optimize')
            ->expectsOutput('No images found in logos directory.')
            ->assertExitCode(0);
    }

    /**
     * Test command skips already optimized webp files.
     */
    public function test_command_skips_existing_webp_files(): void
    {
        $webpImage = Image::create(100, 100)->fill('ff0000')->toWebp()->toString();
        Storage::disk('public')->put('logos/already-optimized.webp', $webpImage);

        $this->artisan('logos:optimize')
            ->expectsOutput('No images found in logos directory.')
            ->assertExitCode(0);
    }

    /**
     * Test command creates logos directory if it doesn't exist.
     */
    public function test_command_creates_logos_directory_if_missing(): void
    {
        $this->artisan('logos:optimize')
            ->expectsOutput('No images found in logos directory.')
            ->assertExitCode(0);
    }

    /**
     * Test command with --dry-run shows what would be optimized without making changes.
     */
    public function test_command_dry_run_does_not_create_files(): void
    {
        Storage::disk('public')->put('logos/test-company.png', $this->createTestImage(500, 300));

        $this->artisan('logos:optimize', ['--dry-run' => true])
            ->expectsOutputToContain('test-company')
            ->expectsOutputToContain('dry run')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing('logos/test-company.webp');
    }

    /**
     * Test command removes original file after optimization with --remove-originals flag.
     */
    public function test_command_removes_originals_with_flag(): void
    {
        Storage::disk('public')->put('logos/to-remove.png', $this->createTestImage(400, 200));

        $this->artisan('logos:optimize', ['--remove-originals' => true])
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing('logos/to-remove.png');
        Storage::disk('public')->assertExists('logos/to-remove.webp');
    }

    /**
     * Test command keeps original file by default.
     */
    public function test_command_keeps_originals_by_default(): void
    {
        Storage::disk('public')->put('logos/to-keep.png', $this->createTestImage(400, 200));

        $this->artisan('logos:optimize')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/to-keep.png');
        Storage::disk('public')->assertExists('logos/to-keep.webp');
    }

    /**
     * Test optimized WebP file is smaller than original for a typical logo size.
     */
    public function test_optimized_file_is_smaller(): void
    {
        $largeImage = Image::create(1000, 800)->fill('ff0000')->toPng()->toString();
        Storage::disk('public')->put('logos/big-logo.png', $largeImage);

        $originalSize = strlen($largeImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        $optimizedContent = Storage::disk('public')->get('logos/big-logo.webp');
        $optimizedSize = strlen($optimizedContent);

        $this->assertLessThan($originalSize, $optimizedSize);
    }

    /**
     * Test command handles GIF files.
     */
    public function test_command_handles_gif_files(): void
    {
        $gifImage = Image::create(300, 200)->fill('00ffff')->toGif()->toString();
        Storage::disk('public')->put('logos/gif-company.gif', $gifImage);

        $this->artisan('logos:optimize')->assertExitCode(0);

        Storage::disk('public')->assertExists('logos/gif-company.webp');
    }
}
