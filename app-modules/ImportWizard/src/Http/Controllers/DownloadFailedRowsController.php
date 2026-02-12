<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use Illuminate\Http\Request;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Models\Import;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFailedRowsController
{
    public function __invoke(Request $request, Import $import): StreamedResponse
    {
        abort_unless(
            (string) $import->team_id === (string) $request->user()?->currentTeam?->id,
            403,
        );

        $firstFailedRow = $import->failedRows()->first();
        $columnHeaders = $firstFailedRow ? array_keys($firstFailedRow->data) : [];
        $columnHeaders[] = 'Import Error';

        return response()->streamDownload(function () use ($import, $columnHeaders): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columnHeaders, escape: '\\');

            $import->failedRows()
                ->lazyById(100)
                ->each(function (FailedImportRow $row) use ($handle): void {
                    $values = array_values($row->data);
                    $values[] = $row->validation_error ?? 'System error';
                    fputcsv($handle, $values, escape: '\\');
                });

            fclose($handle);
        }, "failed-rows-{$import->id}.csv", ['Content-Type' => 'text/csv']);
    }
}
