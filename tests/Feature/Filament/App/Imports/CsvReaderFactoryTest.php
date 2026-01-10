<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Support\CsvReaderFactory;

beforeEach(fn () => Storage::fake('local'));

describe('Delimiter Auto-Detection', function (): void {
    it('detects :dataset delimiter', function (string $delimiter, string $name): void {
        $csvPath = Storage::disk('local')->path("{$name}.csv");
        file_put_contents($csvPath, "name{$delimiter}email{$delimiter}phone\nJohn{$delimiter}john@example.com{$delimiter}555-1234");

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getDelimiter())->toBe($delimiter)
            ->and($reader->getHeader())->toBe(['name', 'email', 'phone']);
    })->with([
        'comma' => [',', 'comma'],
        'semicolon' => [';', 'semicolon'],
        'tab' => ["\t", 'tab'],
        'pipe' => ['|', 'pipe'],
    ]);

    it('falls back to comma when no delimiter detected', function (): void {
        $csvPath = Storage::disk('local')->path('single.csv');
        file_put_contents($csvPath, "single_column\nvalue1\nvalue2");

        expect((new CsvReaderFactory)->createFromPath($csvPath)->getDelimiter())->toBe(',');
    });
});

describe('Header Parsing', function (): void {
    it('parses headers from :dataset', function (string $scenario, string $content, ?int $offset, array $expected): void {
        $csvPath = Storage::disk('local')->path("{$scenario}.csv");
        file_put_contents($csvPath, $content);

        $reader = $offset !== null
            ? (new CsvReaderFactory)->createFromPath($csvPath, headerOffset: $offset)
            : (new CsvReaderFactory)->createFromPath($csvPath);

        expect($reader->getHeader())->toBe($expected);
    })->with([
        'first row by default' => ['headers', "First Name,Last Name,Email\nJohn,Doe,john@example.com", null, ['First Name', 'Last Name', 'Email']],
        'custom offset' => ['custom-offset', "# Comment line\nname,email\njohn,john@example.com", 1, ['name', 'email']],
    ]);
});

describe('Edge Cases', function (): void {
    it('handles :dataset', function (string $scenario, string $content, array $expectedResult): void {
        $csvPath = Storage::disk('local')->path("{$scenario}.csv");
        file_put_contents($csvPath, $content);

        $reader = (new CsvReaderFactory)->createFromPath($csvPath);
        $records = iterator_to_array($reader->getRecords());

        expect($reader->getHeader())->toBe($expectedResult['header']);

        if (isset($expectedResult['records'])) {
            expect($records)->toBe($expectedResult['records']);
        }

        foreach ($expectedResult['record_check'] ?? [] as $index => $check) {
            foreach ($check as $key => $value) {
                expect($records[$index][$key])->toBe($value);
            }
        }
    })->with([
        'empty CSV with only headers' => ['empty', 'name,email', ['header' => ['name', 'email'], 'records' => []]],
        'quoted values with delimiters' => ['quoted', "name,description\n\"Acme, Inc.\",\"A company, with commas\"", ['header' => ['name', 'description'], 'record_check' => [1 => ['name' => 'Acme, Inc.', 'description' => 'A company, with commas']]]],
        'UTF-8 content' => ['utf8', "name,city\nCafé,São Paulo\nMüller,München", ['header' => ['name', 'city'], 'record_check' => [1 => ['name' => 'Café', 'city' => 'São Paulo'], 2 => ['name' => 'Müller', 'city' => 'München']]]],
    ]);

    it('iterates over records correctly', function (): void {
        $csvPath = Storage::disk('local')->path('records.csv');
        file_put_contents($csvPath, "name,value\nAlpha,100\nBeta,200\nGamma,300");

        $records = iterator_to_array((new CsvReaderFactory)->createFromPath($csvPath)->getRecords());

        expect($records)->toHaveCount(3)
            ->and($records[1])->toBe(['name' => 'Alpha', 'value' => '100'])
            ->and($records[2])->toBe(['name' => 'Beta', 'value' => '200'])
            ->and($records[3])->toBe(['name' => 'Gamma', 'value' => '300']);
    });
});
