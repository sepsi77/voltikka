<?php

namespace App\Filament\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams every row and every column of one analytics table as a private CSV download.
 * The column list is read at request time, so a new column is exported without a code change.
 */
abstract class AnalyticsTableCsvExport
{
    abstract protected function table(): string;

    abstract protected function filenamePrefix(): string;

    public function __invoke(): StreamedResponse
    {
        $table = $this->table();
        $columns = Schema::getColumnListing($table);
        $filename = $this->filenamePrefix().'-'.now()->toDateString().'.csv';

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
