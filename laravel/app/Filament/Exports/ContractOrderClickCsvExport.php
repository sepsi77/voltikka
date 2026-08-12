<?php

namespace App\Filament\Exports;

use App\Models\ContractOrderClick;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContractOrderClickCsvExport
{
    public function __invoke(): StreamedResponse
    {
        $table = (new ContractOrderClick)->getTable();
        $columns = Schema::getColumnListing($table);
        $filename = 'voltikka-contract-order-clicks-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($columns, $table): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $columns);

            DB::table($table)
                ->select($columns)
                ->chunkByIdDesc(1000, function ($rows) use ($columns, $output): void {
                    foreach ($rows as $row) {
                        fputcsv($output, array_map(
                            fn (string $column): mixed => $row->{$column},
                            $columns,
                        ));
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
