<?php

namespace App\Filament\Resources\ContractOrderClicks\Pages;

use App\Filament\Resources\ContractOrderClicks\ContractOrderClickResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContractOrderClicks extends ListRecords
{
    protected static string $resource = ContractOrderClickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Vie kaikki CSV-tiedostoon')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('filament.admin.contract-order-clicks.export')),
        ];
    }
}
