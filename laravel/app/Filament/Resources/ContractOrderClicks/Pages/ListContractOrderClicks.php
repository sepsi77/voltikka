<?php

namespace App\Filament\Resources\ContractOrderClicks\Pages;

use App\Filament\Resources\ContractOrderClicks\ContractOrderClickResource;
use Filament\Resources\Pages\ListRecords;

class ListContractOrderClicks extends ListRecords
{
    protected static string $resource = ContractOrderClickResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
