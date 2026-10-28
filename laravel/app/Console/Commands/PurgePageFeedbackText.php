<?php

namespace App\Console\Commands;

use App\Models\PageFeedback;
use Illuminate\Console\Command;

/**
 * Deletes the free text of "Löysitkö etsimäsi?" answers after 12 months (user decision
 * 2026-10-28; the privacy page states it). Free text can hold personal data. The vote,
 * reason and goal codes stay, because they cannot identify a person.
 */
class PurgePageFeedbackText extends Command
{
    public const RETENTION_MONTHS = 12;

    protected $signature = 'feedback:purge-text';

    protected $description = 'Delete page feedback free text older than 12 months';

    public function handle(): int
    {
        $cutoff = now()->utc()->subMonths(self::RETENTION_MONTHS);

        $count = PageFeedback::query()
            ->where('occurred_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNotNull('reason_text')->orWhereNotNull('goal_text'))
            ->update(['reason_text' => null, 'goal_text' => null, 'updated_at' => now()]);

        $this->info("Deleted the free text of {$count} feedback answers.");

        return Command::SUCCESS;
    }
}
