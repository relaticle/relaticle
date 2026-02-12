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

        $columnHeaders = $import->headers ?? [];
        $csvHeaders = [...$columnHeaders, 'Import Error'];

        return response()->streamDownload(function () use ($import, $columnHeaders, $csvHeaders): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $csvHeaders, escape: '\\');

            $import->failedRows()
                ->lazyById(100)
                ->each(function (FailedImportRow $row) use ($handle, $columnHeaders): void {
                    $data = $row->data;
                    $values = array_map(fn (string $header) => $data[$header] ?? '', $columnHeaders);
                    $values[] = $row->validation_error ?? 'System error';
                    fputcsv($handle, $values, escape: '\\');
                });

            fclose($handle);
        }, "failed-rows-{$import->id}.csv", ['Content-Type' => 'text/csv']);
    }
}
