<?php

declare(strict_types=1);

use App\Services\Import\ExcelToCsvConverter;
use Illuminate\Http\UploadedFile;

// Use dirname to get reliable path regardless of parallel test workers
const FIXTURES_PATH = __DIR__.'/../../fixtures/imports';

describe('ExcelToCsvConverter', function (): void {
    it('detects xlsx files as excel', function (): void {
        $converter = app(ExcelToCsvConverter::class);

        $file = new UploadedFile(
            FIXTURES_PATH.'/companies.xlsx',
            'companies.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        expect($converter->isExcelFile($file))->toBeTrue();
    });

    it('does not detect csv files as excel', function (): void {
        $converter = app(ExcelToCsvConverter::class);

        $file = new UploadedFile(
            FIXTURES_PATH.'/companies.csv',
            'companies.csv',
            'text/csv',
            null,
            true
        );

        expect($converter->isExcelFile($file))->toBeFalse();
    });

    it('converts xlsx file to csv', function (): void {
        $converter = app(ExcelToCsvConverter::class);

        $file = new UploadedFile(
            FIXTURES_PATH.'/companies.xlsx',
            'companies.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $csvFile = $converter->convert($file);

        expect($csvFile)->toBeInstanceOf(UploadedFile::class);
        expect($csvFile->getRealPath())->toEndWith('.csv');
        expect(file_exists($csvFile->getRealPath()))->toBeTrue();

        $csvContent = file_get_contents($csvFile->getRealPath());
        expect($csvContent)->toContain('name');
        expect($csvContent)->toContain('account_owner_email');
        expect($csvContent)->toContain('Acme Corporation');
        expect($csvContent)->toContain('owner@example.com');
    });

    it('preserves all rows when converting xlsx', function (): void {
        $converter = app(ExcelToCsvConverter::class);

        $file = new UploadedFile(
            FIXTURES_PATH.'/companies.xlsx',
            'companies.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $csvFile = $converter->convert($file);
        $csvContent = file_get_contents($csvFile->getRealPath());
        $lines = array_filter(explode("\n", trim($csvContent)));

        // Header + 5 data rows
        expect($lines)->toHaveCount(6);
    });

    it('converts people xlsx with multiple columns', function (): void {
        $converter = app(ExcelToCsvConverter::class);

        $file = new UploadedFile(
            FIXTURES_PATH.'/people.xlsx',
            'people.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $csvFile = $converter->convert($file);
        $csvContent = file_get_contents($csvFile->getRealPath());

        expect($csvContent)->toContain('name');
        expect($csvContent)->toContain('company_name');
        expect($csvContent)->toContain('custom_fields_emails');
        expect($csvContent)->toContain('custom_fields_phones');
        expect($csvContent)->toContain('John Smith');
        expect($csvContent)->toContain('Acme Corporation');
    });

    it('returns accepted mime types', function (): void {
        $mimeTypes = ExcelToCsvConverter::getAcceptedMimeTypes();

        expect($mimeTypes)->toContain('text/csv');
        expect($mimeTypes)->toContain('application/vnd.ms-excel');
        expect($mimeTypes)->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    });

    it('returns accepted extensions', function (): void {
        $extensions = ExcelToCsvConverter::getAcceptedExtensions();

        expect($extensions)->toContain('csv');
        expect($extensions)->toContain('txt');
        expect($extensions)->toContain('xlsx');
        expect($extensions)->toContain('xls');
    });
});
