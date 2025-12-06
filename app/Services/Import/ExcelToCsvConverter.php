<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

final readonly class ExcelToCsvConverter
{
    /**
     * MIME types that indicate an Excel file.
     *
     * @var array<string>
     */
    public const array EXCEL_MIME_TYPES = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * File extensions that indicate an Excel file.
     *
     * @var array<string>
     */
    public const array EXCEL_EXTENSIONS = ['xls', 'xlsx', 'ods'];

    /**
     * Check if a file is an Excel file.
     */
    public function isExcelFile(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($mimeType, self::EXCEL_MIME_TYPES, true)
            || in_array($extension, self::EXCEL_EXTENSIONS, true);
    }

    /**
     * Convert an Excel file to CSV format.
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function convert(UploadedFile $file): UploadedFile
    {
        if (! $this->isExcelFile($file)) {
            return $file;
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $csvPath = $this->generateTempPath();

        $writer = new Csv($spreadsheet);
        $writer->setSheetIndex($spreadsheet->getActiveSheetIndex());
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\n");
        $writer->setUseBOM(true);
        $writer->save($csvPath);

        // Clean up spreadsheet from memory
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $csvPath,
            $this->generateCsvFilename($file),
            'text/csv',
            null,
            true
        );
    }

    /**
     * Get all acceptable MIME types for import (CSV + Excel).
     *
     * @return array<string>
     */
    public static function getAcceptedMimeTypes(): array
    {
        return [
            'text/csv',
            'text/plain',
            'text/x-csv',
            'application/csv',
            'application/x-csv',
            ...self::EXCEL_MIME_TYPES,
        ];
    }

    /**
     * Get all acceptable file extensions for import.
     *
     * @return array<string>
     */
    public static function getAcceptedExtensions(): array
    {
        return ['csv', 'txt', ...self::EXCEL_EXTENSIONS];
    }

    /**
     * Generate a temporary file path for the CSV.
     */
    private function generateTempPath(): string
    {
        $tempDir = Storage::disk('local')->path('temp');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        return $tempDir.'/'.Str::uuid()->toString().'.csv';
    }

    /**
     * Generate a CSV filename based on the original file.
     */
    private function generateCsvFilename(UploadedFile $originalFile): string
    {
        $originalName = pathinfo($originalFile->getClientOriginalName(), PATHINFO_FILENAME);

        return $originalName.'.csv';
    }
}
