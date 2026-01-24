<?php

namespace App\Console\Commands;

use App\Services\SocialMediaPromptFormatter;
use App\Services\SpotPriceVideoService;
use Illuminate\Console\Command;

class PreviewSocialPrompt extends Command
{
    protected $signature = 'social:preview-prompt';

    protected $description = 'Preview the formatted LLM prompt for social media text generation';

    public function handle(
        SpotPriceVideoService $videoService,
        SocialMediaPromptFormatter $promptFormatter
    ): int {
        $this->info('Fetching video data...');

        $videoData = $videoService->getDailyVideoData();

        $this->info('Formatting prompt...');
        $this->newLine();

        $prompt = $promptFormatter->formatPrompt($videoData);

        $this->line($prompt);

        $this->newLine();
        $this->info(sprintf('Prompt length: %d characters', strlen($prompt)));

        return Command::SUCCESS;
    }
}
