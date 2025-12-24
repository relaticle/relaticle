<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Jobs\StreamingImportCsv;
use Relaticle\ImportWizard\Models\Import;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    \Relaticle\CustomFields\Services\TenantContextService::setTenantId($this->team->id);

    // Create a custom field section for people
    $section = CustomFieldSection::withoutGlobalScopes()->create([
        'code' => 'contact_information',
        'name' => 'Contact Information',
        'type' => 'section',
        'entity_type' => 'people',
        'tenant_id' => $this->team->id,
        'sort_order' => 1,
    ]);

    // Create the emails custom field for people
    CustomField::withoutGlobalScopes()->create([
        'custom_field_section_id' => $section->id,
        'code' => 'emails',
        'name' => 'Emails',
        'type' => 'email',
        'entity_type' => 'people',
        'tenant_id' => $this->team->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);

    Storage::fake('local');
});

describe('End-to-End People Import', function (): void {
    it('can import 5 people with companies from CSV using real queue jobs', function (): void {
        // Generate CSV with 5 people for easier debugging
        $csvData = "name,company_name,custom_fields_emails\n";
        $expectedPeople = [];

        for ($i = 1; $i <= 5; $i++) {
            $name = "Person {$i}";
            $company = "Company {$i}";
            $email = "person{$i}@example.com";

            $csvData .= "{$name},{$company},{$email}\n";
            $expectedPeople[] = [
                'name' => $name,
                'company' => $company,
                'email' => $email,
            ];
        }

        // Save CSV to storage
        $csvPath = 'test-imports/people-5.csv';
        Storage::disk('local')->put($csvPath, $csvData);

        // Create Import model
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

        // Create and dispatch batch of streaming import jobs
        $columnMap = [
            'name' => 'name',
            'company_name' => 'company_name',
            'custom_fields_emails' => 'custom_fields_emails',
        ];

        // Create and execute a single job for all 5 rows
        $job = new StreamingImportCsv(
            import: $import,
            startRow: 0,
            rowCount: 5,
            columnMap: $columnMap,
            options: [],
        );

        $job->handle();

        // Verify results
        expect(People::count())->toBe(5);
        expect(Company::count())->toBe(5); // 5 unique companies

        expect($import->fresh()->processed_rows)->toBe(5);
        expect($import->fresh()->successful_rows)->toBe(5);

        // Verify each person was created correctly
        foreach ($expectedPeople as $expected) {
            $person = People::where('name', $expected['name'])->first();

            expect($person)->not->toBeNull();
            expect($person->company->name)->toBe($expected['company']);

            // Verify email custom field
            $emailsValue = $person->customFieldValues()
                ->withoutGlobalScopes()
                ->whereHas('customField', fn ($q) => $q->where('code', 'emails'))
                ->first();

            expect($emailsValue)->not->toBeNull();
            expect($emailsValue->json_value)->toContain($expected['email']);
        }
    });

    it('handles duplicate people correctly based on email', function (): void {
        // Create existing person
        $existingCompany = Company::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Existing Company',
        ]);

        $existingPerson = People::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Old Name',
            'company_id' => $existingCompany->id,
        ]);

        // Add email custom field value
        $emailField = CustomField::where('code', 'emails')->where('tenant_id', $this->team->id)->first();
        $existingPerson->customFieldValues()->create([
            'custom_field_id' => $emailField->id,
            'tenant_id' => $this->team->id,
            'json_value' => ['john@example.com'],
        ]);

        // Import CSV with same email but different name
        $csvData = "name,company_name,custom_fields_emails\n";
        $csvData .= "John Doe,New Company,john@example.com\n";

        $csvPath = 'test-imports/duplicate-test.csv';
        Storage::disk('local')->put($csvPath, $csvData);

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

        $job = new StreamingImportCsv(
            import: $import,
            startRow: 0,
            rowCount: 1,
            columnMap: [
                'name' => 'name',
                'company_name' => 'company_name',
                'custom_fields_emails' => 'custom_fields_emails',
            ],
            options: [],
        );

        $job->handle();

        // Should update existing person, not create new one
        expect(People::count())->toBe(1);

        $person = People::first();
        expect($person->id)->toBe($existingPerson->id);
        expect($person->name)->toBe('John Doe'); // Name updated
        expect($person->company->name)->toBe('New Company'); // Company updated
    });

    it('imports large dataset efficiently', function (): void {
        // Generate CSV with 1000 people
        $csvData = "name,company_name,custom_fields_emails\n";

        for ($i = 1; $i <= 1000; $i++) {
            $csvData .= "Person {$i},Company ".ceil($i / 100).",person{$i}@example.com\n";
        }

        $csvPath = 'test-imports/people-1000.csv';
        Storage::disk('local')->put($csvPath, $csvData);

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

        // Process in chunks of 100
        $chunkSize = 100;
        $totalRows = 1000;
        $currentOffset = 0;

        while ($currentOffset < $totalRows) {
            $rowsInThisChunk = min($chunkSize, $totalRows - $currentOffset);

            $job = new StreamingImportCsv(
                import: $import,
                startRow: $currentOffset,
                rowCount: $rowsInThisChunk,
                columnMap: [
                    'name' => 'name',
                    'company_name' => 'company_name',
                    'custom_fields_emails' => 'custom_fields_emails',
                ],
                options: [],
            );

            $job->handle();

            $currentOffset += $rowsInThisChunk;
        }

        // Verify all records imported
        expect(People::count())->toBe(1000);
        expect(Company::count())->toBe(10); // 100 people per company

        expect($import->fresh()->processed_rows)->toBe(1000);
        expect($import->fresh()->successful_rows)->toBe(1000);
    })->skip('Skip by default for speed - run manually when needed');

    it('handles partial failures gracefully', function (): void {
        // CSV with mix of valid and invalid data
        $csvData = "name,company_name,custom_fields_emails\n";
        $csvData .= "Valid Person 1,Company A,valid1@example.com\n";
        $csvData .= ",Company B,invalid@example.com\n"; // Missing required name field
        $csvData .= "Valid Person 2,Company C,valid2@example.com\n";

        $csvPath = 'test-imports/mixed-validity.csv';
        Storage::disk('local')->put($csvPath, $csvData);

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

        $job = new StreamingImportCsv(
            import: $import,
            startRow: 0,
            rowCount: 3,
            columnMap: [
                'name' => 'name',
                'company_name' => 'company_name',
                'custom_fields_emails' => 'custom_fields_emails',
            ],
            options: [],
        );

        $job->handle();

        // Should import valid rows and skip invalid ones
        expect($import->fresh()->processed_rows)->toBe(3);
        expect($import->fresh()->successful_rows)->toBe(2); // Only 2 valid rows

        // Verify only valid people were created
        expect(People::where('name', 'Valid Person 1')->exists())->toBeTrue();
        expect(People::where('name', 'Valid Person 2')->exists())->toBeTrue();
        expect(People::count())->toBe(2); // Only 2 people total
    });
});
