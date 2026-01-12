<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Jobs\StreamingImportCsv;
use Relaticle\ImportWizard\Models\Import;

beforeEach(function (): void {
    Storage::fake('local');
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    createEmailsCustomField($this->team);
});

describe('End-to-End People Import', function (): void {
    it('imports people with companies from CSV', function (): void {
        $csvData = "name,company_name,custom_fields_emails\n";
        for ($i = 1; $i <= 5; $i++) {
            $csvData .= "Person {$i},Company {$i},person{$i}@example.com\n";
        }

        Storage::disk('local')->put($csvPath = 'test-imports/people-5.csv', $csvData);

        $import = Import::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'file_name' => 'people-5.csv',
            'file_path' => $csvPath,
            'importer' => PeopleImporter::class,
            'total_rows' => 5,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        (new StreamingImportCsv(
            import: $import,
            startRow: 0,
            rowCount: 5,
            columnMap: ['name' => 'name', 'rel_company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            options: [],
        ))->handle();

        expect(People::count())->toBe(5)
            ->and(Company::count())->toBe(5)
            ->and($import->fresh())->processed_rows->toBe(5)->successful_rows->toBe(5);

        for ($i = 1; $i <= 5; $i++) {
            $person = People::where('name', "Person {$i}")->first();
            expect($person)->not->toBeNull()->company->name->toBe("Company {$i}");

            $emailValue = $person->customFieldValues()
                ->withoutGlobalScopes()
                ->whereHas('customField', fn ($q) => $q->where('code', 'emails'))
                ->first();
            expect($emailValue)->not->toBeNull()->json_value->toContain("person{$i}@example.com");
        }
    });

    it('handles duplicate detection by email', function (): void {
        $existingCompany = Company::factory()->for($this->team)->create(['name' => 'Existing Company']);
        $existingPerson = People::factory()->for($this->team)->create(['name' => 'Old Name', 'company_id' => $existingCompany->id]);

        $emailField = CustomField::where('code', 'emails')->where('tenant_id', $this->team->id)->first();
        $existingPerson->customFieldValues()->create([
            'custom_field_id' => $emailField->id,
            'tenant_id' => $this->team->id,
            'json_value' => ['john@example.com'],
        ]);

        Storage::disk('local')->put($csvPath = 'test-imports/duplicate-test.csv', "name,custom_fields_emails\nJohn Doe,john@example.com\n");

        $import = Import::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'file_name' => 'duplicate-test.csv',
            'file_path' => $csvPath,
            'importer' => PeopleImporter::class,
            'total_rows' => 1,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        (new StreamingImportCsv($import, 0, 1, ['name' => 'name', 'custom_fields_emails' => 'custom_fields_emails'], []))->handle();

        expect(People::count())->toBe(1);

        $person = People::first();
        expect($person)
            ->id->toBe($existingPerson->id)
            ->name->toBe('John Doe')
            ->company_id->toBe($existingCompany->id);
    });

    it('handles partial failures gracefully', function (): void {
        Storage::disk('local')->put(
            $csvPath = 'test-imports/mixed-validity.csv',
            "name,company_name,custom_fields_emails\nValid Person 1,Company A,valid1@example.com\n,Company B,invalid@example.com\nValid Person 2,Company C,valid2@example.com\n"
        );

        $import = Import::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'file_name' => 'mixed-validity.csv',
            'file_path' => $csvPath,
            'importer' => PeopleImporter::class,
            'total_rows' => 3,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        (new StreamingImportCsv(
            $import,
            0,
            3,
            ['name' => 'name', 'rel_company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            []
        ))->handle();

        expect($import->fresh())
            ->processed_rows->toBe(3)
            ->successful_rows->toBe(2)
            ->and(People::count())->toBe(2)
            ->and(People::where('name', 'Valid Person 1')->exists())->toBeTrue()
            ->and(People::where('name', 'Valid Person 2')->exists())->toBeTrue();
    });

    it('imports large dataset efficiently', function (): void {
        $csvData = "name,company_name,custom_fields_emails\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csvData .= "Person {$i},Company ".ceil($i / 100).",person{$i}@example.com\n";
        }

        Storage::disk('local')->put($csvPath = 'test-imports/people-1000.csv', $csvData);

        $import = Import::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'file_name' => 'people-1000.csv',
            'file_path' => $csvPath,
            'importer' => PeopleImporter::class,
            'total_rows' => 1000,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        $columnMap = ['name' => 'name', 'rel_company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            (new StreamingImportCsv($import, $offset, 100, $columnMap, []))->handle();
        }

        expect(People::count())->toBe(1000)
            ->and(Company::count())->toBe(10)
            ->and($import->fresh())->processed_rows->toBe(1000)->successful_rows->toBe(1000);
    })->skip('Skip by default for speed - run manually when needed');
});
