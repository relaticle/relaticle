<?php

declare(strict_types=1);

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ImportSessionData;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;
use Relaticle\ImportWizard\Filament\Pages\ImportNotes;
use Relaticle\ImportWizard\Filament\Pages\ImportOpportunities;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;
use Relaticle\ImportWizard\Jobs\StreamingImportCsv;
use Relaticle\ImportWizard\Livewire\ImportWizard;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    createEmailsCustomField($this->team);
});

describe('Import Pages', function (): void {
    it('renders :dataset import page correctly', function (string $pageClass, string $expectedTitle, string $expectedType): void {
        Livewire::test($pageClass)
            ->assertSuccessful()
            ->assertSee($expectedTitle);

        expect($pageClass::shouldRegisterNavigation())->toBeFalse()
            ->and($pageClass::getEntityType())->toBe($expectedType);
    })->with([
        'companies' => [ImportCompanies::class, 'Import Companies', 'companies'],
        'people' => [ImportPeople::class, 'Import People', 'people'],
        'opportunities' => [ImportOpportunities::class, 'Import Opportunities', 'opportunities'],
        'tasks' => [ImportTasks::class, 'Import Tasks', 'tasks'],
        'notes' => [ImportNotes::class, 'Import Notes', 'notes'],
    ]);
});

describe('Upload Step', function (): void {
    it('uploads valid CSV and extracts headers', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name,email,phone\nAcme Corp,a@a.com,555-1234\nTech Inc,b@b.com,555-5678"))
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', ['name', 'email', 'phone'])
            ->assertSet('rowCount', 2);
    });

    it('rejects :dataset', function (UploadedFile $file): void {
        wizardTest($this->team)->set('uploadedFile', $file)->assertHasErrors('uploadedFile');
    })->with([
        'too many rows' => [fn () => createTestCsv("name\n".implode("\n", array_map(fn ($i) => "Company {$i}", range(1, 10001))))],
        'invalid file type' => [fn () => UploadedFile::fake()->create('test.pdf', 100)],
    ]);

    it('handles :dataset CSV edge case', function (string $scenario, string $csv, array $expected): void {
        $component = wizardTest($this->team)->set('uploadedFile', createTestCsv($csv));

        if (isset($expected['rowCount'])) {
            $component->assertSet('rowCount', $expected['rowCount']);
        }
        if (isset($expected['csvHeaders'])) {
            $component->assertSet('csvHeaders', $expected['csvHeaders']);
        }
        if (isset($expected['step'])) {
            $component->call('nextStep')->assertSet('currentStep', $expected['step']);
        }
        if (isset($expected['hasErrors'])) {
            $component->assertHasErrors('uploadedFile');
        }
    })->with([
        'duplicate columns rejected' => ['duplicate', "name,email,name\nAcme,a@a.com,Dup", ['hasErrors' => true, 'rowCount' => 0]],
        'empty with headers' => ['empty', 'name,email,phone', ['rowCount' => 0, 'csvHeaders' => ['name', 'email', 'phone']]],
        'special characters' => ['special', "name\n\"Company, Inc.\"\n\"Company \"\"Quoted\"\" Name\"", ['rowCount' => 2, 'step' => ImportWizard::STEP_MAP]],
        'unicode' => ['unicode', "name\n日本会社\nДоверитель\nشركة", ['rowCount' => 3, 'step' => ImportWizard::STEP_MAP]],
        'blank values' => ['blank', "name,phone\nAcme Corp,\nTech Inc,", ['rowCount' => 2]],
    ]);

    it('handles large CSV efficiently', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\n".implode("\n", array_map(fn ($i) => "Company {$i}", range(1, 1000)))))
            ->assertSet('rowCount', 1000)
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });
});

describe('Column Mapping Step', function (): void {
    it('handles :dataset mapping operation', function (string $scenario, string $csv, array $mappingOps, string $expected): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv($csv))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        foreach ($mappingOps as $op) {
            $component->set($op['field'], $op['value']);
        }

        $component->assertSet($mappingOps[array_key_last($mappingOps)]['field'], $expected);
    })->with([
        'auto-maps matching' => ['auto-map', "name,email\nAcme,a@a.com", [['field' => 'columnMap.name', 'value' => 'name']], 'name'],
        'manual mapping' => ['manual', "company_name,contact_email\nAcme,a@a.com", [['field' => 'columnMap.name', 'value' => 'company_name']], 'company_name'],
        'unmapping' => ['unmap', "name,email\nAcme,a@a.com", [['field' => 'columnMap.name', 'value' => 'name'], ['field' => 'columnMap.name', 'value' => '']], ''],
    ]);

    it('blocks advancement when required fields not mapped', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("email,phone\na@a.com,555-1234"))
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);
    });

    it('handles unique identifier warning flow', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("email\na@a.com"))
            ->call('nextStep')
            ->set('columnMap.account_owner_email', 'email')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW);
    });
});

describe('Review and Preview Steps', function (): void {
    it('allows value corrections and skipping', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp\nBad Company"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('correctValue', 'name', 'Acme Corp', 'Acme Corporation')
            ->call('skipValue', 'name', 'Bad Company');
    });

    it('supports pagination for many values', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\n".implode("\n", array_map(fn ($i) => "Company {$i}", range(1, 150)))))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('loadMoreValues', 'name');
    });

    it('reaches preview and shows badges', function (): void {
        Company::factory()->for($this->team)->create(['name' => 'Existing Corp']);

        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nExisting Corp\nNew Corp"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);
    });

    it('dispatches import jobs on execute', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp\nTech Inc"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });
});

describe('Complete Workflow', function (): void {
    it('completes full :dataset import', function (string $entityType, string $csv, array $mappings): void {
        if ($entityType === 'people') {
            Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
        }

        $component = wizardTest($this->team, $entityType)
            ->set('uploadedFile', createTestCsv($csv))
            ->call('nextStep');

        foreach ($mappings as $field => $value) {
            $component->set("columnMap.{$field}", $value);
        }

        $component->call('nextStep');
        if ($component->get('mountedActions')) {
            $component->callMountedAction();
        }

        $component->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    })->with([
        'company' => ['companies', "name,account_owner_email\nAcme,a@a.com\nTech,b@b.com", ['name' => 'name', 'account_owner_email' => 'account_owner_email']],
        'people' => ['people', "name,company_name,custom_fields_emails\nJohn,Acme Corp,john@a.com", ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails']],
    ]);

    it('completes workflow with value corrections', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp\nTech Inc"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('correctValue', 'name', 'Acme Corp', 'Acme Corporation')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class);
    });
});

describe('Navigation and State', function (): void {
    it('preserves state when navigating and resets on resetWizard', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name,email\nAcme,a@a.com"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->set('columnMap.account_owner_email', 'email')
            ->call('nextStep')
            ->callMountedAction()
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSet('columnMap.name', 'name')
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', ['name', 'email'])
            ->call('resetWizard')
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD)
            ->assertSet('csvHeaders', [])
            ->assertSet('columnMap', []);
    });

    it('prevents skipping steps', function (): void {
        wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme"))
            ->call('goToStep', ImportWizard::STEP_PREVIEW)
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD);
    });
});

describe('Preview Job Cancellation', function (): void {
    it('stores session data in consolidated cache during preview', function (): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);

        $sessionId = $component->get('sessionId');
        expect($sessionId)->not->toBeNull();

        $cached = Cache::get(ImportSessionData::cacheKey($sessionId));
        expect($cached)->not->toBeNull()
            ->and($cached)->toHaveKeys(['team_id', 'input_hash', 'total', 'processed', 'heartbeat']);
    });

    it('skips preview regeneration when inputs unchanged', function (): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);

        $initialHash = $component->get('previewInputHash');
        expect($initialHash)->not->toBeNull();

        $component
            ->call('previousStep')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->assertSet('previewInputHash', $initialHash);
    });

    it('regenerates preview when column mapping changes', function (): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name,email\nAcme Corp,acme@test.com"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);

        $initialHash = $component->get('previewInputHash');

        $component
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_REVIEW)
            ->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->set('columnMap.account_owner_email', 'email')
            ->call('nextStep');

        if ($component->get('mountedActions')) {
            $component->callMountedAction();
        }

        $component->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);

        $newHash = $component->get('previewInputHash');
        expect($newHash)->not->toBe($initialHash);
    });

    it('prevents double import execution', function (): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp\nTech Inc"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW)
            ->assertSet('importStarted', false);

        $component->call('executeImport')->assertSet('importStarted', true);
        $component->call('executeImport');

        Queue::assertPushed(StreamingImportCsv::class, 1);
    });

    it('clears session cache on reset wizard', function (): void {
        $component = wizardTest($this->team)
            ->set('uploadedFile', createTestCsv("name\nAcme Corp"))
            ->call('nextStep')
            ->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers')
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_PREVIEW);

        $sessionId = $component->get('sessionId');
        expect(Cache::has(ImportSessionData::cacheKey($sessionId)))->toBeTrue();

        $component->call('resetWizard');

        expect(Cache::has(ImportSessionData::cacheKey($sessionId)))->toBeFalse();
    });
});
