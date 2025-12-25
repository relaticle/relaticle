<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Livewire\ImportWizard;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    Storage::fake('local');
});

describe('Unique Identifier Column Configuration', function () {
    test('company importer defines correct unique identifier columns', function () {
        expect(CompanyImporter::getUniqueIdentifierColumns())
            ->toBe(['id', 'name']);
    });

    test('people importer defines correct unique identifier columns', function () {
        expect(PeopleImporter::getUniqueIdentifierColumns())
            ->toBe(['id', 'custom_fields_emails']);
    });

    test('opportunity importer defines correct unique identifier columns', function () {
        expect(OpportunityImporter::getUniqueIdentifierColumns())
            ->toBe(['id', 'name']);
    });

    test('task importer defines correct unique identifier columns', function () {
        expect(TaskImporter::getUniqueIdentifierColumns())
            ->toBe(['id', 'title']);
    });

    test('note importer skips unique identifier warning', function () {
        expect(NoteImporter::skipUniqueIdentifierWarning())->toBeTrue();
    });

    test('company importer does not skip unique identifier warning', function () {
        expect(CompanyImporter::skipUniqueIdentifierWarning())->toBeFalse();
    });

    test('people importer does not skip unique identifier warning', function () {
        expect(PeopleImporter::skipUniqueIdentifierWarning())->toBeFalse();
    });

    test('company importer provides correct missing unique identifiers message', function () {
        expect(CompanyImporter::getMissingUniqueIdentifiersMessage())
            ->toBe('For Companies, map a Company name or Record ID column');
    });

    test('people importer provides correct missing unique identifiers message', function () {
        expect(PeopleImporter::getMissingUniqueIdentifiersMessage())
            ->toBe('For People, map an Email addresses or Record ID column');
    });

    test('task importer provides correct missing unique identifiers message', function () {
        expect(TaskImporter::getMissingUniqueIdentifiersMessage())
            ->toBe('For Tasks, map a Title or Record ID column');
    });
});

describe('Unique Identifier Warning Flow', function () {
    test('step does not advance when no unique identifier is mapped for companies', function () {
        // CSV with no name or id column - only unmapped columns
        $csv = "email,phone,address\njohn@example.com,555-1234,123 Main St";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // Verify no unique identifier columns are mapped
            ->assertSet('columnMap.id', '')
            ->assertSet('columnMap.name', '')
            // Try to proceed - step should remain at MAP because warning modal is shown
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    test('step advances when user confirms unique identifier warning', function () {
        $csv = "email,phone,address\njohn@example.com,555-1234,123 Main St";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // Call nextStep to trigger warning
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // Now call the action to confirm (bypassing the warning)
            ->callAction('proceedWithoutUniqueIdentifiers')
            // Should now be on Review step
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });

    test('step advances directly when name column is mapped for companies', function () {
        // CSV with name column - should auto-map and not trigger warning
        $csv = "name,email,phone\nAcme Corp,acme@example.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // name should be auto-mapped
            ->assertSet('columnMap.name', 'name')
            // Should proceed directly to Review step - no warning
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });

    test('step advances directly when id column is mapped for companies', function () {
        // CSV with id and name columns
        $csv = "id,name,email\n01JFXYZ123ABC456DEF789GHI0,Acme Corp,acme@example.com";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // Both id and name should be auto-mapped
            ->assertSet('columnMap.id', 'id')
            ->assertSet('columnMap.name', 'name')
            // Should proceed directly to Review step
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });

    test('note importer skips warning and advances directly', function () {
        // CSV with only title - the required field for notes
        $csv = "title,content\nMeeting Notes,Important discussion";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'notes', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSet('columnMap.title', 'title')
            // Notes skip the unique identifier warning entirely
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });

    test('people importer triggers warning when only name is mapped', function () {
        // For people, name is NOT a unique identifier (id and email are)
        $csv = "name,phone,address\nJohn Doe,555-1234,123 Main St";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'people', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // name is mapped but NOT a unique identifier for people
            ->assertSet('columnMap.name', 'name')
            // id and email not mapped
            ->assertSet('columnMap.id', '')
            // Try to proceed - should stay at MAP step (warning triggered)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    test('task importer advances when title is mapped', function () {
        $csv = "title,description\nFollow up call,Call client tomorrow";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'tasks', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            // title should be auto-mapped and is a unique identifier for tasks
            ->assertSet('columnMap.title', 'title')
            // Should proceed directly to Review step
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });
});

describe('Warning Action Configuration', function () {
    test('proceedWithoutUniqueIdentifiers action exists', function () {
        $csv = "email,phone\njohn@example.com,555-1234";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/'])
            ->set('uploadedFile', $file)
            ->call('nextStep')
            ->assertActionExists('proceedWithoutUniqueIdentifiers');
    });

    test('proceedWithoutUniqueIdentifiers action has correct modal heading', function () {
        $component = Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/']);

        $action = $component->instance()->proceedWithoutUniqueIdentifiersAction();

        expect($action->getModalHeading())->toBe('Avoid creating duplicate records');
    });

    test('proceedWithoutUniqueIdentifiers action has warning color', function () {
        $component = Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/']);

        $action = $component->instance()->proceedWithoutUniqueIdentifiersAction();

        expect($action->getColor())->toBe('warning');
    });

    test('proceedWithoutUniqueIdentifiers action requires confirmation', function () {
        $component = Livewire::test(ImportWizard::class, ['entityType' => 'companies', 'returnUrl' => '/']);

        $action = $component->instance()->proceedWithoutUniqueIdentifiersAction();

        expect($action->isConfirmationRequired())->toBeTrue();
    });
});
