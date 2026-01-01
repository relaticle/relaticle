<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Services\CsvReaderFactory;

beforeEach(function () {
    Storage::fake('local');
});

describe('Delimiter Auto-Detection', function () {
    test('it detects comma delimiter', function () {
        $csvContent = "name,email,phone\nJohn,john@example.com,555-1234\nJane,jane@example.com,555-5678";
        $csvPath = Storage::disk('local')->path('comma.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe(',')
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    });

    test('it detects semicolon delimiter', function () {
        $csvContent = "name;email;phone\nJohn;john@example.com;555-1234\nJane;jane@example.com;555-5678";
        $csvPath = Storage::disk('local')->path('semicolon.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe(';')
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    });

    test('it detects tab delimiter', function () {
        $csvContent = "name\temail\tphone\nJohn\tjohn@example.com\t555-1234";
        $csvPath = Storage::disk('local')->path('tab.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe("\t")
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    });

    test('it detects pipe delimiter', function () {
        $csvContent = "name|email|phone\nJohn|john@example.com|555-1234";
        $csvPath = Storage::disk('local')->path('pipe.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe('|')
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    });

    test('it falls back to comma when no delimiter detected', function () {
        $csvContent = "single_column\nvalue1\nvalue2";
        $csvPath = Storage::disk('local')->path('single.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        // League\CSV defaults to comma when no delimiter is explicitly set
        expect($reader->getDelimiter())->toBe(',');
    });
});

describe('Header Parsing', function () {
    test('it parses headers from first row by default', function () {
        $csvContent = "First Name,Last Name,Email\nJohn,Doe,john@example.com";
        $csvPath = Storage::disk('local')->path('headers.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getHeader())->toBe(['First Name', 'Last Name', 'Email']);
    });

    test('it handles headers with spaces', function () {
        $csvContent = "First Name,Last Name,Email Address\nJohn,Doe,john@example.com";
        $csvPath = Storage::disk('local')->path('spaced-headers.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getHeader())->toBe(['First Name', 'Last Name', 'Email Address']);
    });

    test('it handles custom header offset', function () {
        $csvContent = "# Comment line\nname,email\njohn,john@example.com";
        $csvPath = Storage::disk('local')->path('custom-offset.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath, headerOffset: 1);

        expect($reader->getHeader())->toBe(['name', 'email']);
    });
});

describe('Record Iteration', function () {
    test('it iterates over records correctly', function () {
        $csvContent = "name,value\nAlpha,100\nBeta,200\nGamma,300";
        $csvPath = Storage::disk('local')->path('records.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        $records = iterator_to_array($reader->getRecords());

        expect($records)->toHaveCount(3)
            ->and($records[1])->toBe(['name' => 'Alpha', 'value' => '100'])
            ->and($records[2])->toBe(['name' => 'Beta', 'value' => '200'])
            ->and($records[3])->toBe(['name' => 'Gamma', 'value' => '300']);
    });
});

describe('Edge Cases', function () {
    test('it handles empty CSV files', function () {
        $csvContent = 'name,email';
        $csvPath = Storage::disk('local')->path('empty.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        expect($reader->getHeader())->toBe(['name', 'email'])
            ->and(iterator_to_array($reader->getRecords()))->toBe([]);
    });

    test('it handles CSV with quoted values containing delimiters', function () {
        $csvContent = "name,description\n\"Acme, Inc.\",\"A company, with commas\"";
        $csvPath = Storage::disk('local')->path('quoted.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        $records = iterator_to_array($reader->getRecords());

        expect($records[1]['name'])->toBe('Acme, Inc.')
            ->and($records[1]['description'])->toBe('A company, with commas');
    });

    test('it handles CSV with UTF-8 content', function () {
        $csvContent = "name,city\nCafé,São Paulo\nMüller,München";
        $csvPath = Storage::disk('local')->path('utf8.csv');
        file_put_contents($csvPath, $csvContent);

        $factory = new CsvReaderFactory;
        $reader = $factory->createFromPath($csvPath);

        $records = iterator_to_array($reader->getRecords());

        expect($records[1]['name'])->toBe('Café')
            ->and($records[1]['city'])->toBe('São Paulo')
            ->and($records[2]['name'])->toBe('Müller')
            ->and($records[2]['city'])->toBe('München');
    });
});
