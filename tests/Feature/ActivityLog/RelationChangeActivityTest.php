<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Concerns\LogsLinkChanges;
use App\Models\Concerns\LogsRelationChanges;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityLog\ActivityValue;
use App\Support\ActivityLog\MergedActivityRenderer;
use App\Support\ActivityLog\RelationChangeLog;
use App\Support\ActivityLog\RequestActivityBatch;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;

mutates(RelationChangeLog::class);
mutates(LogsRelationChanges::class);
mutates(LogsLinkChanges::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create(['name' => 'John']);
    $this->workspace = $this->user->personalWorkspace();
    $this->actingAs($this->user);
    Sanctum::actingAs($this->user);
    Filament::setTenant($this->workspace);

    $this->acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Old']);
    $this->globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex New']);
});

function latestTimelineEntryHtml(Model $record): string
{
    return (new MergedActivityRenderer)->render($record->timeline()->get()->first())->render();
}

function latestRelationChange(Model $record): array
{
    return Activity::query()
        ->where('subject_id', $record->getKey())
        ->latest('id')
        ->firstOrFail()
        ->properties
        ->get('custom_field_changes')[0];
}

function startNextRequest(): void
{
    app()->forgetInstance(RequestActivityBatch::class);
    test()->travel(1)->minutes();
}

it('shows a person company change by company name on the timeline', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $this->acme->getKey()]);
    startNextRequest();

    $this->putJson("/api/v1/people/{$person->getKey()}", ['company_id' => $this->globex->getKey()])->assertOk();

    expect(latestTimelineEntryHtml($person))
        ->toContain(__('filament/resources/person.fields.company_id.label'))
        ->toContain('Acme Old')
        ->toContain('Globex New')
        ->not->toContain((string) $this->globex->getKey());
});

it('shows a cleared person company as a change to empty', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $this->acme->getKey()]);
    startNextRequest();

    $this->putJson("/api/v1/people/{$person->getKey()}", ['company_id' => null])->assertOk();

    expect(latestTimelineEntryHtml($person))
        ->toContain('Acme Old')
        ->toContain(__('filament/resources/person.fields.company_id.label'));
});

it('writes no relation change when a person is saved without touching the company', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $this->acme->getKey()]);
    startNextRequest();

    $this->putJson("/api/v1/people/{$person->getKey()}", ['name' => 'Renamed Person'])->assertOk();

    expect(latestTimelineEntryHtml($person))
        ->toContain('Name')
        ->not->toContain('Acme Old');
});

it('shows opportunity company and contact changes by name on the timeline', function (): void {
    $contact = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Pat Contact']);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $this->acme->getKey()]);
    startNextRequest();

    $this->putJson("/api/v1/opportunities/{$opportunity->getKey()}", [
        'company_id' => $this->globex->getKey(),
        'contact_id' => $contact->getKey(),
    ])->assertOk();

    expect(latestTimelineEntryHtml($opportunity))
        ->toContain(__('filament/resources/opportunity.fields.company_id.label'))
        ->toContain(__('filament/resources/opportunity.fields.contact_id.label'))
        ->toContain('Acme Old')
        ->toContain('Globex New')
        ->toContain('Pat Contact')
        ->not->toContain((string) $contact->getKey());
});

it('shows companies and assignees added to a task on its timeline', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    startNextRequest();

    $this->putJson("/api/v1/tasks/{$task->getKey()}", [
        'company_ids' => [$this->acme->getKey()],
        'assignee_ids' => [$this->user->getKey()],
    ])->assertOk();

    expect(latestTimelineEntryHtml($task))
        ->toContain(__('filament/resources/task.fields.companies.label'))
        ->toContain('Acme Old')
        ->toContain(__('filament/resources/task.fields.assignees.label'))
        ->toContain('John');
});

it('shows a company removed from a task on its timeline', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->companies()->attach($this->acme);
    startNextRequest();

    $this->putJson("/api/v1/tasks/{$task->getKey()}", ['company_ids' => [$this->globex->getKey()]])->assertOk();

    expect(latestTimelineEntryHtml($task))
        ->toContain('Acme Old')
        ->toContain('Globex New');
});

it('shows people linked to a note on its timeline', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Pat Contact']);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    startNextRequest();

    $this->putJson("/api/v1/notes/{$note->getKey()}", ['people_ids' => [$person->getKey()]])->assertOk();

    expect(latestTimelineEntryHtml($note))
        ->toContain(__('filament/resources/note.fields.people.label'))
        ->toContain('Pat Contact');
});

it('logs a task linked from the company side on the task timeline', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    startNextRequest();

    $this->acme->tasks()->attach($task);

    expect(latestTimelineEntryHtml($task))
        ->toContain(__('filament/resources/task.fields.companies.label'))
        ->toContain('Acme Old');
});

it('writes a single activity row for a person created with a company', function (): void {
    $this->postJson('/api/v1/people', ['name' => 'Pat Contact', 'company_id' => $this->acme->getKey()])->assertCreated();

    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->where('name', 'Pat Contact')->sole();

    expect(Activity::query()->where('subject_id', $person->getKey())->pluck('event')->all())->toBe(['created']);
});

it('shows a company account owner change by user name on the timeline', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['account_owner_id' => null]);
    startNextRequest();

    $this->putJson("/api/v1/companies/{$company->getKey()}", ['account_owner_id' => $this->user->getKey()])->assertOk();

    expect(latestTimelineEntryHtml($company))
        ->toContain(__('filament/resources/company.fields.account_owner_id.label'))
        ->toContain('John')
        ->not->toContain((string) $this->user->getKey());

    expect(latestRelationChange($company))->toMatchArray([
        'code' => 'account_owner',
        'old' => ['value' => null, 'label' => ActivityValue::EMPTY],
        'new' => ['value' => $this->user->getKey(), 'label' => 'John'],
    ]);
});

it('records an assignee removed from a task as a change to empty', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->assignees()->attach($this->user);
    startNextRequest();

    $this->putJson("/api/v1/tasks/{$task->getKey()}", ['assignee_ids' => []])->assertOk();

    expect(latestRelationChange($task))->toMatchArray([
        'code' => 'assignees',
        'label' => __('filament/resources/task.fields.assignees.label'),
        'old' => ['value' => $this->user->getKey(), 'label' => 'John'],
        'new' => ['value' => null, 'label' => ActivityValue::EMPTY],
    ]);
});

it('records a person unlinked from a note as a change to empty', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Pat Contact']);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach($person);
    startNextRequest();

    $this->putJson("/api/v1/notes/{$note->getKey()}", ['people_ids' => []])->assertOk();

    expect(latestRelationChange($note))->toMatchArray([
        'code' => 'people',
        'label' => __('filament/resources/note.fields.people.label'),
        'old' => ['value' => $person->getKey(), 'label' => 'Pat Contact'],
        'new' => ['value' => null, 'label' => ActivityValue::EMPTY],
    ]);
});

it('labels opportunities linked to a task or a note', function (string $model, string $endpoint): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big Deal']);
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();
    startNextRequest();

    $this->putJson("/api/v1/{$endpoint}/{$record->getKey()}", ['opportunity_ids' => [$opportunity->getKey()]])->assertOk();

    expect(latestRelationChange($record))->toMatchArray([
        'code' => 'opportunities',
        'label' => 'Opportunities',
        'new' => ['value' => $opportunity->getKey(), 'label' => 'Big Deal'],
    ]);
})->with([
    'task' => [Task::class, 'tasks'],
    'note' => [Note::class, 'notes'],
]);
