<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;

beforeEach(function (): void {
    Storage::fake('local');
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
});

describe('Assignee Attachment', function (): void {
    it('attaches assignee by email', function (): void {
        $assignee = User::factory()->create();
        $assignee->teams()->attach($this->team);

        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title', 'assignee_email' => 'assignee_email'], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'Assigned Task', 'assignee_email' => $assignee->email];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Assigned Task')->first();

        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($assignee->id);
    });

    it('imports task without assignee when email is empty', function (): void {
        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title'], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'Unassigned Task'];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Unassigned Task')->first();

        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(0);
    });

    it('ignores assignee when email does not match team member', function (): void {
        $nonTeamMember = User::factory()->create();

        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title', 'assignee_email' => 'assignee_email'], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'Task With Invalid Assignee', 'assignee_email' => $nonTeamMember->email];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Task With Invalid Assignee')->first();

        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(0);
    });
});
