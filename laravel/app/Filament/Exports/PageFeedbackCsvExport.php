<?php

namespace App\Filament\Exports;

use App\Models\PageFeedback;

final class PageFeedbackCsvExport extends AnalyticsTableCsvExport
{
    protected function table(): string
    {
        return (new PageFeedback)->getTable();
    }

    protected function filenamePrefix(): string
    {
        return 'voltikka-page-feedback';
    }
}
