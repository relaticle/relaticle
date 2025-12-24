<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Services\ImportPreviewService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

describe('Preview Generation', function () {
    test('it generates preview for company import', function () {
        // Create test CSV
        $csvContent = "name\nAcme Corp\nGlobex Inc\nInitech";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        expect($result->totalRows)->toBe(3)
            ->and($result->createCount)->toBe(3)
            ->and($result->updateCount)->toBe(0)
            ->and($result->rows)->toHaveCount(3)
            ->and($result->isSampled)->toBeFalse();
    });

    test('it detects updates for existing companies', function () {
        // Create existing company
        Company::factory()->create([
            'name' => 'Acme Corp',
            'team_id' => $this->team->id,
        ]);

        // Create test CSV
        $csvContent = "name\nAcme Corp\nNew Company";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'update'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        expect($result->createCount)->toBe(1)
            ->and($result->updateCount)->toBe(1);
    });

    test('it samples large files and scales counts', function () {
        // Create CSV with more than sample size rows
        $rows = ['name'];
        for ($i = 1; $i <= 1500; $i++) {
            $rows[] = "Company $i";
        }
        $csvContent = implode("\n", $rows);
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
            sampleSize: 1000,
        );

        expect($result->totalRows)->toBe(1500)
            ->and($result->isSampled)->toBeTrue()
            ->and($result->sampleSize)->toBe(1000)
            ->and($result->rows)->toHaveCount(1000);
    });
});

describe('Value Corrections', function () {
    test('it applies value corrections to preview data', function () {
        // Create test CSV with values to correct
        $csvContent = "name,status\nAcme Corp,active\nGlobex Inc,inactive";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        // Apply corrections: 'active' -> 'Active', 'inactive' -> 'Inactive'
        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
            valueCorrections: [
                'name' => ['Acme Corp' => 'ACME Corporation'],
            ],
        );

        // The correction should be applied
        expect($result->rows[0]['name'])->toBe('ACME Corporation')
            ->and($result->rows[1]['name'])->toBe('Globex Inc');
    });
});

describe('Row Metadata', function () {
    test('it includes row index in preview data', function () {
        $csvContent = "name\nFirst\nSecond\nThird";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        expect($result->rows[0]['_row_index'])->toBe(1)
            ->and($result->rows[1]['_row_index'])->toBe(2)
            ->and($result->rows[2]['_row_index'])->toBe(3);
    });

    test('it marks new records correctly', function () {
        $csvContent = "name\nNew Company";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        expect($result->rows[0]['_is_new'])->toBeTrue()
            ->and($result->rows[0]['_update_method'])->toBeNull()
            ->and($result->rows[0]['_record_id'])->toBeNull();
    });

    test('it marks existing records with update method', function () {
        $company = Company::factory()->create([
            'name' => 'Existing Corp',
            'team_id' => $this->team->id,
        ]);

        $csvContent = "name\nExisting Corp";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'update'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        expect($result->rows[0]['_is_new'])->toBeFalse()
            ->and($result->rows[0]['_update_method'])->toBe('attribute')
            ->and($result->rows[0]['_record_id'])->toBe($company->id);
    });
});

describe('Company Match Enrichment', function () {
    test('it enriches people import with company match data', function () {
        // Create a company to match against
        Company::factory()->create([
            'name' => 'Acme Corp',
            'team_id' => $this->team->id,
        ]);

        $csvContent = "first_name,last_name,company_name\nJohn,Doe,Acme Corp";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: PeopleImporter::class,
            csvPath: $csvPath,
            columnMap: [
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'company_name' => 'company_name',
            ],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        // Should have company match metadata
        expect($result->rows[0])->toHaveKeys([
            '_company_name',
            '_company_match_type',
            '_company_match_count',
            '_company_id',
        ]);
    });

    test('it does not enrich company import with company match data', function () {
        $csvContent = "name\nSome Company";
        $csvPath = Storage::disk('local')->path('test-import.csv');
        file_put_contents($csvPath, $csvContent);

        $service = app(ImportPreviewService::class);

        $result = $service->preview(
            importerClass: CompanyImporter::class,
            csvPath: $csvPath,
            columnMap: ['name' => 'name'],
            options: ['duplicate_handling' => 'skip'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        // Company import should not have company match metadata
        expect($result->rows[0])->not->toHaveKey('_company_name')
            ->and($result->rows[0])->not->toHaveKey('_company_match_type');
    });
});
