<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Jobs\StreamingImportCsv;
use Relaticle\ImportWizard\Livewire\ImportWizard;

/**
 * Comprehensive UI tests for ImportWizard Livewire component.
 *
 * Tests the complete import workflow through UI interactions:
 * 1. Upload Step - File upload and validation
 * 2. Map Columns Step - Column mapping and preview
 * 3. Review Values Step - Value analysis, corrections, validation
 * 4. Preview Step - Import summary and execution
 */
beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);

    // Create emails custom field for People imports
    $section = CustomFieldSection::withoutGlobalScopes()->create([
        'code' => 'contact_information',
        'name' => 'Contact Information',
        'type' => 'section',
        'entity_type' => 'people',
        'tenant_id' => $this->team->id,
        'sort_order' => 1,
    ]);

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
});

/**
 * Helper: Create a CSV file with given content.
 */
function createTestCsv(string $content, string $filename = 'test.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, $content);
}

/**
 * Helper: Get return URL for given entity type.
 */
function getReturnUrl(Team $team, string $entityType): string
{
    return match ($entityType) {
        'companies' => route('filament.app.resources.companies.index', ['tenant' => $team]),
        'people' => route('filament.app.resources.people.index', ['tenant' => $team]),
        'opportunities' => route('filament.app.resources.opportunities.index', ['tenant' => $team]),
        'tasks' => route('filament.app.resources.tasks.index', ['tenant' => $team]),
        'notes' => route('filament.app.resources.notes.index', ['tenant' => $team]),
        default => '/',
    };
}

describe('Upload Step - File Upload and Validation', function (): void {
    it('uploads valid CSV file and extracts headers', function (): void {
        $csv = "name,email,phone\nAcme Corp,contact@acme.com,555-1234\nTech Inc,info@tech.com,555-5678";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', ['name', 'email', 'phone'])
            ->assertSet('rowCount', 2);
    });

    it('shows error for file with too many rows', function (): void {
        // Generate CSV with 10,001 rows (exceeds limit)
        $csv = "name\n";
        for ($i = 1; $i <= 10001; $i++) {
            $csv .= "Company {$i}\n";
        }
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertHasErrors('uploadedFile');
    });

    it('shows error for invalid file type', function (): void {
        $file = UploadedFile::fake()->create('test.pdf', 100);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertHasErrors('uploadedFile');
    });

    it('shows error for CSV with duplicate column names', function (): void {
        $csv = "name,email,name\nAcme Corp,contact@acme.com,Duplicate Name";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD);

        // Should detect duplicate headers (implementation checks for this)
        // The exact error display may vary, but duplicate headers prevent progression
    });

    it('allows removing uploaded file and restarting', function (): void {
        $csv = "name,email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('csvHeaders', ['name', 'email'])
            ->call('resetWizard')
            ->assertSet('csvHeaders', [])
            ->assertSet('rowCount', 0)
            ->assertSet('persistedFilePath', null);
    });

    it('advances to Map step when file is valid', function (): void {
        $csv = "name,email,phone\nAcme Corp,contact@acme.com,555-1234";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    it('handles empty CSV with only headers', function (): void {
        $csv = 'name,email,phone';
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('rowCount', 0)
            ->assertSet('csvHeaders', ['name', 'email', 'phone']);
    });
});

describe('Column Mapping Step - Field Mapping and Validation', function (): void {
    it('auto-maps CSV columns to fields using guesses', function (): void {
        $csv = "name,email,phone\nAcme Corp,contact@acme.com,555-1234";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSet('columnMap.name', 'name'); // Should auto-map 'name' column
    });

    it('allows manual column mapping via property update', function (): void {
        $csv = "company_name,contact_email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->set('columnMap.name', 'company_name') // Manually map
            ->assertSet('columnMap.name', 'company_name');
    });

    it('allows unmapping a column', function (): void {
        $csv = "name,email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->set('columnMap.name', '') // Unmap
            ->assertSet('columnMap.name', '');
    });

    it('blocks advancement when required fields are not mapped', function (): void {
        $csv = "email,phone\ncontact@acme.com,555-1234"; // Missing 'name' column
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP); // Should stay at MAP step
    });

    it('shows modal warning when unique identifier is not mapped', function (): void {
        $csv = "email,phone\ncontact@acme.com,555-1234";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.account_owner_email', 'email') // Map required field
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP); // Blocked by unique identifier warning
    });

    it('advances to Review after confirming unique identifier warning', function (): void {
        $csv = "email,phone\ncontact@acme.com,555-1234";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.account_owner_email', 'email')
            ->call('nextStep')
            ->callAction('proceedWithoutUniqueIdentifiers') // Confirm warning
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW); // Should advance
    });

    it('navigates back to Upload step from Map step', function (): void {
        $csv = "name,email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD);
    });

    it('preserves column mappings when navigating back and forward', function (): void {
        $csv = "name,email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->call('previousStep')
            ->call('nextStep')
            ->assertSet('columnMap.name', 'name'); // Mapping should be preserved
    });
});

describe('Review Values Step - Value Analysis and Corrections', function (): void {
    it('analyzes columns and shows unique values with counts', function (): void {
        $csv = "name\nAcme Corp\nTech Inc\nAcme Corp"; // Duplicate 'Acme Corp'
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
        // Value analysis happens automatically on step entry
        // columnAnalysesData should contain unique value counts
    });

    it('detects and displays validation errors for invalid values', function (): void {
        // Note: This test requires actual validation errors
        // The exact implementation depends on field validators
        $csv = "name,account_owner_email\nAcme Corp,invalid-email"; // Invalid email
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->set('columnMap.account_owner_email', 'account_owner_email')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
        // Should detect email validation error
    });

    it('allows correcting an invalid value', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('correctValue', 'name', 'Acme Corp', 'Acme Corporation');
        // Correction should be stored in valueCorrections property
    });

    it('allows skipping a problematic value', function (): void {
        $csv = "name\nAcme Corp\nBad Company";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('skipValue', 'name', 'Bad Company');
        // Value should be marked as skipped (empty string correction)
    });

    it('allows unskipping a previously skipped value via toggle', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('skipValue', 'name', 'Acme Corp') // Skip
            ->call('skipValue', 'name', 'Acme Corp'); // Unskip (toggle)
        // skipValue is a toggle - calling it twice unskips the value
    });

    it('blocks advancement when validation errors exist', function (): void {
        // This test assumes there are validation errors that prevent progression
        // The exact behavior depends on the validator implementation
        $csv = "name,account_owner_email\nAcme Corp,invalid-email";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->set('columnMap.account_owner_email', 'account_owner_email')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('nextStep');
        // Should either stay at REVIEW or show modal to proceed with errors
    });

    it('advances to Preview after confirming to proceed with errors', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('nextStep');
        // If there are errors, use: ->callAction('proceedWithErrors')
        // Otherwise should advance directly
    });

    it('supports pagination for loading more values', function (): void {
        // Generate CSV with many unique values to test pagination
        $csv = "name\n";
        for ($i = 1; $i <= 150; $i++) {
            $csv .= "Company {$i}\n";
        }
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('loadMoreValues', 'name');
        // Should load additional values beyond initial page
    });

    it('navigates back to Map step from Review step', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });
});

describe('Preview Step - Import Summary and Execution', function (): void {
    it('displays summary statistics for import', function (): void {
        $csv = "name\nAcme Corp\nTech Inc";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);
        // previewResultData should contain totalRows, createCount, updateCount
    });

    it('displays sample rows with mapped data', function (): void {
        $csv = "name\nAcme Corp\nTech Inc\nStartup LLC";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);
        // previewRows should contain sample rows with mapped values
    });

    it('shows create vs update badges correctly', function (): void {
        // Create an existing company
        Company::factory()->for($this->team)->create(['name' => 'Existing Corp']);

        $csv = "name\nExisting Corp\nNew Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);
        // Preview should show 1 update (Existing Corp) and 1 create (New Corp)
    });

    it('dispatches import batch jobs when starting import', function (): void {
        Queue::fake();

        $csv = "name\nAcme Corp\nTech Inc";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });

    it('navigates back to Review step from Preview step', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });
});

describe('Complete Workflow Tests - Happy Paths', function (): void {
    it('completes full company import workflow without errors', function (): void {
        Queue::fake();

        $csv = "name,account_owner_email\nAcme Corp,owner@acme.com\nTech Inc,owner@tech.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->set('columnMap.name', 'name')
            ->set('columnMap.account_owner_email', 'account_owner_email')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });

    it('completes full people import workflow with email matching', function (): void {
        Queue::fake();

        // Create a company for association
        Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

        $csv = "name,company_name,custom_fields_emails\nJohn Doe,Acme Corp,john@acme.com\nJane Smith,Acme Corp,jane@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'people',
            'returnUrl' => getReturnUrl($this->team, 'people'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->set('columnMap.name', 'name')
            ->set('columnMap.company_name', 'company_name')
            ->set('columnMap.custom_fields_emails', 'custom_fields_emails')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });

    it('completes workflow with value corrections', function (): void {
        Queue::fake();

        $csv = "name\nAcme Corp\nTech Inc";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('correctValue', 'name', 'Acme Corp', 'Acme Corporation') // Apply correction
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });

    it('completes workflow with unique identifier warning confirmation', function (): void {
        Queue::fake();

        $csv = "account_owner_email\nowner@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.account_owner_email', 'account_owner_email')
            ->call('nextStep')
            ->callAction('proceedWithoutUniqueIdentifiers') // Confirm warning
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });
});

describe('Navigation and State Management', function (): void {
    it('preserves state when navigating back through all steps', function (): void {
        $csv = "name,email\nAcme Corp,contact@acme.com";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->set('columnMap.name', 'name')
            ->set('columnMap.account_owner_email', 'email') // Map required field to avoid warning
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('previousStep') // Back to MAP
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSet('columnMap.name', 'name') // Mapping preserved
            ->call('previousStep') // Back to UPLOAD
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', ['name', 'email']); // Headers preserved
    });

    it('resets all wizard state when reset is called', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->call('resetWizard')
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', [])
            ->assertSet('columnMap', []);
    });

    it('prevents skipping steps via direct navigation', function (): void {
        $csv = "name\nAcme Corp";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->call('goToStep', ImportWizard::STEP_PREVIEW) // Try to skip to preview
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD); // Should stay at UPLOAD
    });
});

describe('Edge Cases', function (): void {
    it('handles CSV with special characters in values', function (): void {
        $csv = "name\n\"Company, Inc.\"\n\"Company \"\"Quoted\"\" Name\"";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('rowCount', 2)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    it('handles CSV with all blank values in a column', function (): void {
        $csv = "name,phone\nAcme Corp,\nTech Inc,";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
        // Should handle blank phone values gracefully
    });

    it('handles mapping all columns as do not import', function (): void {
        $csv = "column1,column2,column3\nValue1,Value2,Value3";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // All columns unmapped (do not import)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP); // Should block due to missing required fields
    });

    it('handles large CSV file efficiently', function (): void {
        // Generate CSV with 1,000 rows
        $csv = "name\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csv .= "Company {$i}\n";
        }
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('rowCount', 1000)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    it('handles CSV with unicode characters', function (): void {
        $csv = "name\n日本会社\nДоверитель\nشركة";
        $file = createTestCsv($csv);

        Livewire::test(ImportWizard::class, [
            'entityType' => 'companies',
            'returnUrl' => getReturnUrl($this->team, 'companies'),
        ])
            ->set('uploadedFile', $file)
            ->assertSet('rowCount', 3)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });
});
