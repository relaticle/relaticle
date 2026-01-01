<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Services\CsvReaderFactory;

beforeEach(function () {
    Storage::fake('local');
});

describe('Delimiter Auto-Detection', function () {
    it('detects :dataset delimiter', function (string $delimiter, string $name) {
        $csvContent = "name{$delimiter}email{$delimiter}phone\nJohn{$delimiter}john@example.com{$delimiter}555-1234";
        $csvPath = Storage::disk('local')->path("{$name}.csv");
        file_put_contents($csvPath, $csvContent);

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe($delimiter)
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    })->with([
        'comma' => [',', 'comma'],
        'semicolon' => [';', 'semicolon'],
        'tab' => ["\t", 'tab'],
        'pipe' => ['|', 'pipe'],
    ]);

    test('falls back to comma when no delimiter detected', function () {
        $csvContent = "single_column\nvalue1\nvalue2";
        $csvPath = Storage::disk('local')->path('single.csv');
        file_put_contents($csvPath, $csvContent);

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe(',');
    });
});

describe('Header Parsing', function () {
    test('parses headers from first row by default', function () {
        $csvContent = "First Name,Last Name,Email\nJohn,Doe,john@example.com";
        $csvPath = Storage::disk('local')->path('headers.csv');
        file_put_contents($csvPath, $csvContent);

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getHeader())->toBe(['First Name', 'Last Name', 'Email']);
    });

    test('handles custom header offset', function () {
        $csvContent = "# Comment line\nname,email\njohn,john@example.com";
        $csvPath = Storage::disk('local')->path('custom-offset.csv');
        file_put_contents($csvPath, $csvContent);

        $reader = (new CsvReaderFactory)->createFromPath($csvPath, headerOffset: 1);

        expect($reader->getHeader())->toBe(['name', 'email']);
    });
});

describe('Edge Cases', function () {
    test('handles empty CSV with only headers', function () {
        $csvPath = Storage::disk('local')->path('empty.csv');
        file_put_contents($csvPath, 'name,email');

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getHeader())->toBe(['name', 'email'])
            ->and(iterator_to_array($reader->getRecords()))->toBe([]);
    });

    test('handles quoted values containing delimiters', function () {
        $csvContent = "name,description\n\"Acme, Inc.\",\"A company, with commas\"";
        $csvPath = Storage::disk('local')->path('quoted.csv');
        file_put_contents($csvPath, $csvContent);

        $records = iterator_to_array((new CsvReaderFactory)->createFromPath($csvPath)->getRecords());

        expect($records[1]['name'])->toBe('Acme, Inc.')
            ->and($records[1]['description'])->toBe('A company, with commas');
    });

    test('handles UTF-8 content', function () {
        $csvContent = "name,city\nCafé,São Paulo\nMüller,München";
        $csvPath = Storage::disk('local')->path('utf8.csv');
        file_put_contents($csvPath, $csvContent);

        $records = iterator_to_array((new CsvReaderFactory)->createFromPath($csvPath)->getRecords());

        expect($records[1])
            ->name->toBe('Café')
            ->city->toBe('São Paulo')
            ->and($records[2])
            ->name->toBe('Müller')
            ->city->toBe('München');
    });

    test('iterates over records correctly', function () {
        $csvContent = "name,value\nAlpha,100\nBeta,200\nGamma,300";
        $csvPath = Storage::disk('local')->path('records.csv');
        file_put_contents($csvPath, $csvContent);

        $records = iterator_to_array((new CsvReaderFactory)->createFromPath($csvPath)->getRecords());

        expect($records)->toHaveCount(3)
            ->and($records[1])->toBe(['name' => 'Alpha', 'value' => '100'])
            ->and($records[2])->toBe(['name' => 'Beta', 'value' => '200'])
            ->and($records[3])->toBe(['name' => 'Gamma', 'value' => '300']);
    });
});
