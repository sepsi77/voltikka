<?php

namespace App\Filament\Exports;

use App\Models\ContractOrderClick;

final class ContractOrderClickCsvExport extends AnalyticsTableCsvExport
{
    protected function table(): string
    {
        return (new ContractOrderClick)->getTable();
    }

    protected function filenamePrefix(): string
    {
        return 'voltikka-contract-order-clicks';
    }
}
