<?php

namespace Tests\Feature;

use App\Services\SocialMediaPromptFormatter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialMediaPromptFormatterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prompt_date_context_comes_from_video_data(): void
    {
        Carbon::setTestNow(Carbon::create(2030, 6, 1, 10, 0, 0, 'Europe/Helsinki'));

        $prompt = app(SocialMediaPromptFormatter::class)->formatPrompt([
            'as_of' => '2026-01-19T13:15:00+02:00',
            'date' => ['iso' => '2026-01-19'],
            'prices' => ['today' => [], 'tomorrow' => []],
            'statistics' => [],
            'comparison' => ['day_rating' => ['code' => 'unknown']],
            'appliances' => [],
            'ev_charging' => null,
        ]);

        $this->assertStringContainsString('Maanantai 19. tammikuuta 2026', $prompt);
        $this->assertStringNotContainsString('Lauantai 1. kesäkuuta 2030', $prompt);
    }
}
