<?php

namespace App\Filament\Resources\PageFeedback\Pages;

use App\Filament\Resources\PageFeedback\PageFeedbackResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPageFeedback extends ListRecords
{
    protected static string $resource = PageFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Vie kaikki CSV-tiedostoon')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('filament.admin.page-feedback.export')),
        ];
    }
}
