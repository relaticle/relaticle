<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Models\Import;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);
});

describe('Polymorphic Relationship Attachment', function (): void {
    it('attaches task to existing company', function (): void {
        $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);

        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'company_name' => 'company_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'Follow up',
            'company_name' => 'Acme Corp',
        ]);

        ($importer)(['title' => 'Follow up', 'company_name' => 'Acme Corp']);

        $task = Task::query()->where('title', 'Follow up')->first();
        expect($task)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id);
    });

    it('creates and attaches new company when it does not exist', function (): void {
        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'company_name' => 'company_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'New Task',
            'company_name' => 'New Company Inc',
        ]);

        ($importer)(['title' => 'New Task', 'company_name' => 'New Company Inc']);

        $task = Task::query()->where('title', 'New Task')->first();
        $company = Company::query()->where('name', 'New Company Inc')->first();

        expect($task)->not->toBeNull()
            ->and($company)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id);
    });

    it('attaches task to person', function (): void {
        $person = People::factory()->for($this->team, 'team')->create(['name' => 'John Doe']);

        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'person_name' => 'person_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'Contact John',
            'person_name' => 'John Doe',
        ]);

        ($importer)(['title' => 'Contact John', 'person_name' => 'John Doe']);

        $task = Task::query()->where('title', 'Contact John')->first();
        expect($task)->not->toBeNull()
            ->and($task->people)->toHaveCount(1)
            ->and($task->people->first()->id)->toBe($person->id);
    });

    it('attaches task to opportunity', function (): void {
        $opportunity = Opportunity::factory()->for($this->team, 'team')->create(['name' => 'Big Deal']);

        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'opportunity_name' => 'opportunity_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'Follow up on deal',
            'opportunity_name' => 'Big Deal',
        ]);

        ($importer)(['title' => 'Follow up on deal', 'opportunity_name' => 'Big Deal']);

        $task = Task::query()->where('title', 'Follow up on deal')->first();
        expect($task)->not->toBeNull()
            ->and($task->opportunities)->toHaveCount(1)
            ->and($task->opportunities->first()->id)->toBe($opportunity->id);
    });

    it('attaches task to multiple entities', function (): void {
        $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
        $person = People::factory()->for($this->team, 'team')->create(['name' => 'John Doe']);
        $opportunity = Opportunity::factory()->for($this->team, 'team')->create(['name' => 'Big Deal']);

        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            [
                'title' => 'title',
                'company_name' => 'company_name',
                'person_name' => 'person_name',
                'opportunity_name' => 'opportunity_name',
            ],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'Complex Task',
            'company_name' => 'Acme Corp',
            'person_name' => 'John Doe',
            'opportunity_name' => 'Big Deal',
        ]);

        ($importer)([
            'title' => 'Complex Task',
            'company_name' => 'Acme Corp',
            'person_name' => 'John Doe',
            'opportunity_name' => 'Big Deal',
        ]);

        $task = Task::query()->where('title', 'Complex Task')->first();
        expect($task)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->people)->toHaveCount(1)
            ->and($task->opportunities)->toHaveCount(1);
    });

    it('attaches assignee by email', function (): void {
        // The user must be a member of the team
        $assignee = User::factory()->create();
        $assignee->teams()->attach($this->team);

        $import = createImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'assignee_email' => 'assignee_email'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'title' => 'Assigned Task',
            'assignee_email' => $assignee->email,
        ]);

        ($importer)(['title' => 'Assigned Task', 'assignee_email' => $assignee->email]);

        $task = Task::query()->where('title', 'Assigned Task')->first();
        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($assignee->id);
    });
});
