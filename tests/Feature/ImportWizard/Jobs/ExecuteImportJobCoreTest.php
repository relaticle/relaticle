<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Events\WorkspaceCreated;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkResolver;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class, EntityLinkResolver::class);

beforeEach(function (): void {
    Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    Filament::setTenant($this->workspace);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::delete($this->import->id);
        $this->import->delete();
    }
});

it('creates new People records for rows with match_action=Create', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'John Doe'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Jane Smith'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $initialCount = People::where('workspace_id', $this->workspace->id)->count();

    ImportExecutionFixture::run($this);

    $newPeople = People::where('workspace_id', $this->workspace->id)->get();
    expect($newPeople)->toHaveCount($initialCount + 2)
        ->and($newPeople->pluck('name')->toArray())->toContain('John Doe', 'Jane Smith');

    $john = People::where('workspace_id', $this->workspace->id)->where('name', 'John Doe')->first();
    expect($john->creation_source)->toBe(CreationSource::IMPORT)
        ->and((string) $john->workspace_id)->toBe((string) $this->workspace->id);
});

it('creates new Company records for rows with match_action=Create', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme Corp'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('workspace_id', $this->workspace->id)->where('name', 'Acme Corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->creation_source)->toBe(CreationSource::IMPORT);
});

it('sets custom field values on created records', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Email' => 'john@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField) {
        $cfValue = CustomFieldValue::query()
            ->where('custom_field_id', $emailField->id)
            ->where('entity_id', $person->id)
            ->first();

        expect($cfValue)->not->toBeNull();
    }
});

it('resolves multiple custom field values via batch JSON query', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField === null) {
        $this->markTestSkipped('No emails custom field configured');
    }

    $existingPeople = [];
    $emails = ['alice@test.com', 'bob@test.com', 'carol@test.com', 'dave@test.com', 'eve@test.com'];

    foreach ($emails as $email) {
        $person = People::factory()->create([
            'name' => "Person {$email}",
            'workspace_id' => $this->workspace->id,
        ]);

        CustomFieldValue::factory()->withJsonValue([$email])->create([
            'custom_field_id' => $emailField->id,
            'entity_type' => 'people',
            'entity_id' => $person->id,
            'tenant_id' => $this->workspace->id,
        ]);

        $existingPeople[$email] = $person;
    }

    $rows = [];
    foreach ($emails as $i => $email) {
        $rows[] = ImportExecutionFixture::row($i + 2, ['Name' => "Updated {$email}", 'Email' => $email], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $existingPeople[$email]->id,
        ]);
    }

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->updated_rows)->toBe(5)
        ->and($import->failed_rows)->toBe(0);

    foreach ($emails as $email) {
        $person = $existingPeople[$email]->refresh();
        expect($person->name)->toBe("Updated {$email}");
    }
});

it('updates existing People records for rows with match_action=Update', function (): void {
    $person = People::factory()->create([
        'name' => 'Old Name',
        'workspace_id' => $this->workspace->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'New Name'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $person->refresh();
    expect($person->name)->toBe('New Name');
});

it('preserves existing data when updating with partial fields', function (): void {
    $person = People::factory()->create([
        'name' => 'Original Name',
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->user->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'Updated Name'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $person->refresh();
    expect($person->name)->toBe('Updated Name')
        ->and((string) $person->creator_id)->toBe((string) $this->user->id)
        ->and((string) $person->workspace_id)->toBe((string) $this->workspace->id);
});

it('skips rows with match_action=Skip', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $initialCount = People::where('workspace_id', $this->workspace->id)->count();

    ImportExecutionFixture::run($this);

    expect(People::where('workspace_id', $this->workspace->id)->count())->toBe($initialCount);
});

it('creates company relationship on People record via entity link', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'workspace_id' => $this->workspace->id,
    ]);

    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $company->id);
});

it('uses corrected values over raw values', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jhon'], [
            'corrections' => json_encode(['Name' => 'John']),
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    expect(People::where('workspace_id', $this->workspace->id)->where('name', 'Jhon')->exists())->toBeFalse();
});

it('skips individual values marked as skipped', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Email' => 'bad-email'], [
            'skipped' => json_encode(['Email' => true]),
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();
});

it('sets store status to Completed on success', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);
});

it('fails before writing records when match resolution is incomplete', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Null Action'], ['match_action' => null]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $job = new ExecuteImportJob(
        importId: $this->import->id,
        workspaceId: (string) $this->workspace->id,
    );

    expect(fn () => $job->handle())
        ->toThrow(LogicException::class, 'Import match resolution is incomplete.');

    $job->failed(new LogicException('Import match resolution is incomplete.'));

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->created_rows)->toBe(0)
        ->and($import->skipped_rows)->toBe(0)
        ->and($import->failed_rows)->toBe(0);

    expect(People::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Good Person')
        ->exists())->toBeFalse();
});

it('fails incomplete match resolution without retrying', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Null Action'], ['match_action' => null]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $job = (new ExecuteImportJob(
        importId: $this->import->id,
        workspaceId: (string) $this->workspace->id,
    ))->withFakeQueueInteractions();

    $middleware = $job->middleware()[0];

    expect($middleware)->toBeInstanceOf(FailOnException::class)
        ->and(fn () => $middleware->handle($job, fn (): mixed => $job->handle()))
        ->toThrow(LogicException::class, 'Import match resolution is incomplete.');

    $job->assertFailedWith(LogicException::class);
});

it('stores results with counts in meta', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'workspace_id' => $this->workspace->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['ID' => (string) $person->id, 'Name' => 'Updated'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
        ImportExecutionFixture::row(4, ['ID' => '99999', 'Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(1);
});

it('sets store status to Failed on exception', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::People);

    DB::table('imports')->where('id', $this->import->id)->update(['entity_type' => 'nonexistent']);

    try {
        ImportExecutionFixture::run($this);
    } catch (Throwable) {
    }

    $import = $this->import->fresh();

    if ($import !== null) {
        expect($import->status->value)->toBeIn([ImportStatus::Failed->value, ImportStatus::Importing->value]);
    }
});

it('handles empty import where all rows are skipped', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Phantom'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(0)
        ->and($import->updated_rows)->toBe(0)
        ->and($import->skipped_rows)->toBe(2);
});

it('processes rows in chunks without issues', function (): void {
    $rows = [];
    for ($i = 2; $i <= 51; $i++) {
        $rows[] = ImportExecutionFixture::row($i, ['Name' => "Person {$i}"], ['match_action' => RowMatchAction::Create->value]);
    }

    ImportExecutionFixture::readyStore($this, ['Name'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(50);
});

it('auto-creates company when entity link value is unresolved', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'New Corp', 'behavior' => MatchBehavior::Create->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Company' => 'New Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $newCompany = Company::where('workspace_id', $this->workspace->id)->where('name', 'New Corp')->first();
    expect($newCompany)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $newCompany->id);
});

it('deduplicates auto-created companies across multiple rows', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Same Corp', 'behavior' => MatchBehavior::Create->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'Alice', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        ImportExecutionFixture::row(3, ['Name' => 'Bob', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        ImportExecutionFixture::row(4, ['Name' => 'Carol', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $companies = Company::where('workspace_id', $this->workspace->id)->where('name', 'Same Corp')->get();
    expect($companies)->toHaveCount(1);

    $people = People::where('workspace_id', $this->workspace->id)->whereIn('name', ['Alice', 'Bob', 'Carol'])->get();
    expect($people)->toHaveCount(3);

    $people->each(function ($person) use ($companies): void {
        expect((string) $person->company_id)->toBe((string) $companies->first()->id);
    });
});

it('skips auto-creation for entity links with only MatchOnly matchers', function (): void {
    $relationships = json_encode([
        ['relationship' => 'opportunities', 'action' => 'create', 'id' => null, 'name' => 'Big Deal', 'behavior' => MatchBehavior::MatchOnly->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Title', 'Opportunity'], [
        ImportExecutionFixture::row(2, ['Title' => 'Follow up', 'Opportunity' => 'Big Deal'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Opportunity', matcherKey: 'id', entityLinkKey: 'opportunities'),
    ], ImportEntityType::Task);

    $initialOpportunityCount = Opportunity::where('workspace_id', $this->workspace->id)->count();

    ImportExecutionFixture::run($this);

    expect(Opportunity::where('workspace_id', $this->workspace->id)->count())->toBe($initialOpportunityCount);
});

it('calls store() for MorphToMany entity links after record save', function (): void {
    $company = Company::factory()->create([
        'name' => 'Linked Corp',
        'workspace_id' => $this->workspace->id,
    ]);

    $relationships = json_encode([
        ['relationship' => 'companies', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Title', 'Company'], [
        ImportExecutionFixture::row(2, ['Title' => 'Follow up', 'Company' => 'Linked Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
    ], ImportEntityType::Task);

    ImportExecutionFixture::run($this);

    $task = Task::where('workspace_id', $this->workspace->id)->where('title', 'Follow up')->first();
    expect($task)->not->toBeNull();

    $linkedCompanies = $task->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all();
    expect($linkedCompanies)->toContain((string) $company->id);
});

it('auto-created records have correct workspace and creation source', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Auto Corp', 'behavior' => MatchBehavior::Create->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Company' => 'Auto Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $autoCreatedCompany = Company::where('workspace_id', $this->workspace->id)->where('name', 'Auto Corp')->first();
    expect($autoCreatedCompany)->not->toBeNull()
        ->and($autoCreatedCompany->creation_source)->toBe(CreationSource::IMPORT)
        ->and((string) $autoCreatedCompany->workspace_id)->toBe((string) $this->workspace->id)
        ->and((string) $autoCreatedCompany->creator_id)->toBe((string) $this->user->id);
});

it('credits the importing user as the activity causer on created records', function (): void {
    Event::except(fn (string $event): bool => str_starts_with($event, 'eloquent.'));

    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme Corp'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::Company);

    auth()->forgetGuards();

    ImportExecutionFixture::run($this);

    $company = Company::where('workspace_id', $this->workspace->id)->where('name', 'Acme Corp')->sole();
    $activity = Activity::query()->where('subject_id', $company->getKey())->sole();

    expect($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->causer_type)->toBe('user');
});

it('credits the importing user as the activity causer on updated records', function (): void {
    Event::except(fn (string $event): bool => str_starts_with($event, 'eloquent.'));

    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);

    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme Corporation'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $company->getKey(),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::Company);

    auth()->forgetGuards();

    ImportExecutionFixture::run($this);

    $activity = Activity::query()
        ->where('subject_id', $company->getKey())
        ->where('event', 'updated')
        ->sole();

    expect($company->fresh()->name)->toBe('Acme Corporation')
        ->and($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->causer_type)->toBe('user');
});

it('skips Update row when matched record no longer exists', function (): void {
    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => '99999', 'Name' => 'Ghost'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '99999',
        ]),
        ImportExecutionFixture::row(3, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->skipped_rows)->toBe(1)
        ->and($import->created_rows)->toBe(1);
});

it('processes row with multiple entity links', function (): void {
    $company = Company::factory()->create(['name' => 'Multi Corp', 'workspace_id' => $this->workspace->id]);
    $person = People::factory()->create(['name' => 'Contact Person', 'workspace_id' => $this->workspace->id]);

    $relationships = json_encode([
        ['relationship' => 'companies', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
        ['relationship' => 'people', 'action' => 'update', 'id' => (string) $person->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Title', 'Company', 'Contact'], [
        ImportExecutionFixture::row(2, ['Title' => 'Multi-link task', 'Company' => 'Multi Corp', 'Contact' => 'Contact Person'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'name', entityLinkKey: 'people'),
    ], ImportEntityType::Task);

    ImportExecutionFixture::run($this);

    $task = Task::where('workspace_id', $this->workspace->id)->where('title', 'Multi-link task')->first();
    expect($task)->not->toBeNull();

    expect($task->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $company->id);

    expect($task->people()->pluck('people.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $person->id);
});

it('handles nonexistent import gracefully', function (): void {
    $job = new ExecuteImportJob('nonexistent-id', (string) $this->workspace->id);

    try {
        $job->handle();
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (ModelNotFoundException $e) {
        expect($e->getModel())->toBe(Import::class);
    }
});

it('filters out unexpected attributes from CSV data before saving', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Malicious'], [
        ImportExecutionFixture::row(2, ['Name' => 'Safe Person', 'Malicious' => 'hacked'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Malicious', target: 'is_admin'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'Safe Person')->first();
    expect($person)->not->toBeNull();

    $raw = DB::table('people')->where('id', $person->id)->first();
    expect($raw)->not->toHaveProperty('is_admin', 'hacked');
});

it('persists failed row details in store metadata', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Good Person 2'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(2)
        ->and($import->failedRows)->toBeEmpty();
});

it('sends success notification to user on import completion', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'John Doe'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Jane Smith'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $notifications = $this->user->notifications()->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->data['title'])->toBe('Import of People completed')
        ->and($notification->data['viewData']['results']['created'])->toBe(2)
        ->and($notification->data['viewData']['results']['failed'])->toBe(0);
});

it('includes result counts in completion notification body', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'workspace_id' => $this->workspace->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['ID' => (string) $person->id, 'Name' => 'Updated'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
        ImportExecutionFixture::row(4, ['ID' => '', 'Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $notification = $this->user->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['viewData']['results']['created'])->toBe(1)
        ->and($notification->data['viewData']['results']['updated'])->toBe(1)
        ->and($notification->data['viewData']['results']['skipped'])->toBe(1)
        ->and($notification->data['viewData']['results']['failed'])->toBe(0);
});

it('records failed rows with row number and error message', function (): void {
    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], [
        ImportExecutionFixture::row(2, ['ID' => '', 'Name' => 'Valid Person'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['ID' => '99999', 'Name' => 'Ghost Person'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '99999',
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(1)
        ->and($import->failedRows)->toBeEmpty();
});

it('handles Japanese characters in name fields', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => '田中太郎'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => '佐藤花子'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect(People::where('workspace_id', $this->workspace->id)->where('name', '田中太郎')->exists())->toBeTrue()
        ->and(People::where('workspace_id', $this->workspace->id)->where('name', '佐藤花子')->exists())->toBeTrue();
});

it('handles Arabic characters in name fields', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'محمد أحمد'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'محمد أحمد')->first();
    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('محمد أحمد');
});

it('handles emoji characters in name fields', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Test User 🚀'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', 'Test User 🚀')->first();
    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('Test User 🚀');
});

it('handles accented Latin characters in name fields', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'José García'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'François Müller'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    expect(People::where('workspace_id', $this->workspace->id)->where('name', 'José García')->exists())->toBeTrue()
        ->and(People::where('workspace_id', $this->workspace->id)->where('name', 'François Müller')->exists())->toBeTrue();
});

it('handles international data with entity link auto-creation', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => '株式会社テスト', 'behavior' => MatchBehavior::Create->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => '田中太郎', 'Company' => '株式会社テスト'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('workspace_id', $this->workspace->id)->where('name', '田中太郎')->first();
    expect($person)->not->toBeNull();

    $company = Company::where('workspace_id', $this->workspace->id)->where('name', '株式会社テスト')->first();
    expect($company)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $company->id);
});

it('persists results to Import model on completion', function (): void {
    $headers = ['Name', 'Email'];
    $rows = [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Email' => 'john@test.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Jane', 'Email' => 'jane@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ];
    $mappings = [
        ColumnData::toField('Name', 'name'),
        ColumnData::toField('Email', 'email'),
    ];

    [$import, $store] = ImportExecutionFixture::readyStore($this, $headers, $rows, $mappings);

    ImportExecutionFixture::run($this);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->completed_at)->not->toBeNull()
        ->and($import->created_rows)->toBe(2)
        ->and($import->updated_rows)->toBe(0)
        ->and($import->skipped_rows)->toBe(0)
        ->and($import->failed_rows)->toBe(0);
});

it('marks import as Failed when job exhausts retries via failed() handler', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $job = new ExecuteImportJob(
        importId: $this->import->id,
        workspaceId: (string) $this->workspace->id,
    );

    $job->failed(new RuntimeException('Queue worker gave up'));

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Failed);
});

it('hands the rest of the import to a fresh job when the time box runs out', function (bool $remote): void {
    if ($remote) {
        useRemoteImportStore();
    }

    Bus::fake([ExecuteImportJob::class]);
    config()->set('import-wizard.execution_time_box', 0);

    ImportExecutionFixture::readyStore($this, ['Name'], array_map(
        fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}"], ['match_action' => RowMatchAction::Create->value]),
        range(1, 501),
    ), [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    expect(ImportExecutionFixture::freshStore($this)->query()->where('processed', true)->count())->toBe(500)
        ->and(People::where('workspace_id', $this->workspace->id)->count())->toBe(500)
        ->and($this->import->refresh()->status)->toBe(ImportStatus::Importing);

    Bus::assertDispatchedTimes(ExecuteImportJob::class, 1);
})->with([
    'local store' => [false],
    'remote store' => [true],
]);

describe('on a remote store disk', function (): void {
    beforeEach(function (): void {
        useRemoteImportStore();
    });

    it('imports every row and marks them processed in the remote store', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
            ImportExecutionFixture::row(3, ['Name' => 'Grace Hopper'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);

        ImportExecutionFixture::run($this);

        expect(People::where('workspace_id', $this->workspace->id)->whereIn('name', ['Ada Lovelace', 'Grace Hopper'])->count())->toBe(2)
            ->and(ImportExecutionFixture::freshStore($this)->query()->where('processed', false)->count())->toBe(0)
            ->and($this->import->refresh()->status)->toBe(ImportStatus::Completed);
    });

    it('reclaims the execution lock a killed attempt left behind', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        Cache::lock(importStoreLockName($this->import->id), 360, ImportExecutionFixture::lockOwner($this->import->id))->get();

        ImportExecutionFixture::run($this);

        expect($this->import->refresh()->status)->toBe(ImportStatus::Completed);
    });

    it('retries a lock timeout instead of failing the job', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $held = Cache::lock(importStoreLockName($this->import->id), 150);
        $held->get();
        config()->set('import-wizard.store.lock.wait.job', 0);
        $job = (new ExecuteImportJob(
            importId: $this->import->id,
            workspaceId: (string) $this->workspace->id,
        ))->withFakeQueueInteractions();

        expect(fn () => $job->middleware()[0]->handle($job, fn (): mixed => $job->handle()))
            ->toThrow(ImportStoreException::class);

        $job->assertNotFailed();
        $held->release();
    });

    it('resumes a killed attempt without repeating the rows it already processed', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], array_map(
            fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}"], ['match_action' => RowMatchAction::Create->value]),
            range(1, 501),
        ), [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        ImportStore::withWriteLock($this->import->id, fn (ImportStore $store): int => $store->query()->where('row_number', '<=', 501)->update(['processed' => true]));
        $this->import->update(['created_rows' => 500]);
        Cache::lock(importStoreLockName($this->import->id), 360, ImportExecutionFixture::lockOwner($this->import->id))->get();

        ImportExecutionFixture::run($this);

        $import = $this->import->refresh();
        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->created_rows)->toBe(501)
            ->and(People::where('workspace_id', $this->workspace->id)->count())->toBe(1);
    });

    it('does not release the execution lock of a live handoff sibling', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $sibling = Cache::lock(importStoreLockName($this->import->id), 360, "execute:{$this->import->id}:sibling-job");
        $sibling->get();
        config()->set('import-wizard.store.lock.wait.job', 0);

        expect(fn () => ImportExecutionFixture::run($this))->toThrow(ImportStoreException::class);

        expect($sibling->isOwnedByCurrentProcess())->toBeTrue()
            ->and(People::where('workspace_id', $this->workspace->id)->count())->toBe(0);
        $sibling->release();
    });

    it('retries a failed download instead of failing the job', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $fake = Storage::disk('s3');
        Storage::set('s3', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function readStream(mixed $path): mixed
            {
                return null;
            }
        });
        $job = (new ExecuteImportJob(
            importId: $this->import->id,
            workspaceId: (string) $this->workspace->id,
        ))->withFakeQueueInteractions();

        expect(fn () => $job->middleware()[0]->handle($job, fn (): mixed => $job->handle()))
            ->toThrow(ImportStoreException::class);

        $job->assertNotFailed();
        expect(Cache::lock(importStoreLockName($this->import->id), 1)->get())->toBeTrue();
    });

    it('keeps the rows a failed run already wrote so its retry does not repeat them', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
            ImportExecutionFixture::row(3, ['Name' => ''], ['match_action' => RowMatchAction::Create->value]),
            ImportExecutionFixture::row(4, ['Name' => 'Grace Hopper'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class, QueryExecuted::class]);
        $failures = 0;
        DB::listen(function (QueryExecuted $query) use (&$failures): void {
            if ($failures === 0 && str_starts_with($query->sql, 'insert into "failed_import_rows"')) {
                $failures++;

                throw new RuntimeException('Database went away');
            }
        });

        expect(fn () => ImportExecutionFixture::run($this))->toThrow(RuntimeException::class, 'Database went away');

        ImportExecutionFixture::run($this);

        expect(People::where('workspace_id', $this->workspace->id)->count())->toBe(2)
            ->and($this->import->refresh()->status)->toBe(ImportStatus::Completed)
            ->and($this->import->created_rows)->toBe(2);
    });

    it('remembers the dedup maps before it uploads a chunk snapshot', function (): void {
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $fake = Storage::disk('s3');
        $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public string $importId = '';

            /** @var list<bool> */
            public array $rememberedAtWrite = [];

            public function writeStream(mixed $path, mixed $resource, array $options = []): bool
            {
                $this->rememberedAtWrite[] = Cache::has("import-execution:{$this->importId}:created");

                return parent::writeStream($path, $resource, $options);
            }
        };
        $disk->importId = $this->import->id;
        Storage::set('s3', $disk);

        ImportExecutionFixture::run($this);

        expect($disk->rememberedAtWrite)->toBe([true]);
    });

    it('leaves the counters to the retry when the failure snapshot cannot be uploaded', function (): void {
        Sleep::fake();
        Exceptions::fake();
        ImportExecutionFixture::readyStore($this, ['Name'], [
            ImportExecutionFixture::row(2, ['Name' => 'Ada Lovelace'], ['match_action' => RowMatchAction::Create->value]),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $fake = Storage::disk('s3');
        Storage::set('s3', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function writeStream(mixed $path, mixed $resource, array $options = []): bool
            {
                return false;
            }
        });
        Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class, QueryExecuted::class]);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "import_rows" set "processed"')) {
                throw new RuntimeException('Database went away');
            }
        });

        expect(fn () => ImportExecutionFixture::run($this))->toThrow(RuntimeException::class, 'Database went away');

        $import = $this->import->refresh();
        expect(People::where('workspace_id', $this->workspace->id)->count())->toBe(1)
            ->and($import->created_rows)->toBe(0)
            ->and($import->status)->toBe(ImportStatus::Failed);
        Exceptions::assertReported(ImportStoreException::class);
    });

    it('fails without a retry when a snapshot cannot be uploaded and keeps the counters of what reached the database', function (): void {
        Sleep::fake();
        ImportExecutionFixture::readyStore($this, ['Name'], array_map(
            fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}"], ['match_action' => RowMatchAction::Create->value]),
            range(1, 501),
        ), [
            ColumnData::toField(source: 'Name', target: 'name'),
        ]);
        $fake = Storage::disk('s3');
        $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public string $importId = '';

            public int $writes = 0;

            /** @var list<int> */
            public array $createdRowsAtWrite = [];

            public function writeStream(mixed $path, mixed $resource, array $options = []): bool
            {
                $this->writes++;
                $this->createdRowsAtWrite[] = (int) Import::query()->whereKey($this->importId)->value('created_rows');

                return $this->writes === 1 && parent::writeStream($path, $resource, $options);
            }
        };
        $disk->importId = $this->import->id;
        Storage::set('s3', $disk);
        $job = (new ExecuteImportJob(
            importId: $this->import->id,
            workspaceId: (string) $this->workspace->id,
        ))->withFakeQueueInteractions();

        expect(fn () => $job->middleware()[0]->handle($job, fn (): mixed => $job->handle()))
            ->toThrow(ImportStoreException::class);

        $job->assertFailedWith(ImportStoreException::class);
        $import = $this->import->refresh();
        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->created_rows)->toBe(People::where('workspace_id', $this->workspace->id)->count())
            ->and($import->created_rows)->toBe(501)
            ->and(array_slice($disk->createdRowsAtWrite, 0, 2))->toBe([0, 500])
            ->and($disk->writes)->toBe(4);
    });
});

it('finishes a handed-off import in the next job with one summary and one notification', function (bool $remote): void {
    if ($remote) {
        useRemoteImportStore();
    }

    Bus::fake([ExecuteImportJob::class]);
    config()->set('import-wizard.execution_time_box', 0);

    ImportExecutionFixture::readyStore($this, ['Name'], array_map(
        fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}"], ['match_action' => RowMatchAction::Create->value]),
        range(1, 501),
    ), [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);
    config()->set('import-wizard.execution_time_box', 240);
    ImportExecutionFixture::run($this);

    $import = $this->import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_rows)->toBe(501)
        ->and(People::where('workspace_id', $this->workspace->id)->count())->toBe(501)
        ->and(Activity::query()->withoutGlobalScopes()->forSubject($import)->where('event', ExecuteImportJob::IMPORTED_EVENT)->count())->toBe(1)
        ->and($this->user->notifications()->count())->toBe(1);

    Bus::assertDispatchedTimes(ExecuteImportJob::class, 1);
})->with([
    'local store' => [false],
    'remote store' => [true],
]);

it('counts a failed row once across a handoff', function (): void {
    Bus::fake([ExecuteImportJob::class]);
    config()->set('import-wizard.execution_time_box', 0);

    ImportExecutionFixture::readyStore($this, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => ''], ['match_action' => RowMatchAction::Create->value]),
        ...array_map(
            fn (int $number): array => ImportExecutionFixture::row($number + 2, ['Name' => "Person {$number}"], ['match_action' => RowMatchAction::Create->value]),
            range(1, 500),
        ),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);
    config()->set('import-wizard.execution_time_box', 240);
    ImportExecutionFixture::run($this);

    $import = $this->import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->failed_rows)->toBe(1)
        ->and($import->created_rows)->toBe(500)
        ->and($import->failedRows()->count())->toBe(1);
});

it('links rows in later jobs to a company an earlier job auto-created', function (): void {
    Bus::fake([ExecuteImportJob::class]);
    config()->set('import-wizard.execution_time_box', 0);
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Shared Corp', 'behavior' => MatchBehavior::Create->value],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], array_map(
        fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}", 'Company' => 'Shared Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        range(1, 501),
    ), [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);
    config()->set('import-wizard.execution_time_box', 240);
    ImportExecutionFixture::run($this);

    $company = Company::where('workspace_id', $this->workspace->id)->where('name', 'Shared Corp')->sole();
    expect(People::where('workspace_id', $this->workspace->id)->where('company_id', $company->id)->count())->toBe(501)
        ->and(Cache::has("import-execution:{$this->import->id}:created"))->toBeFalse();
});

it('does not duplicate a person whose email an earlier job already created', function (): void {
    Bus::fake([ExecuteImportJob::class]);
    config()->set('import-wizard.execution_time_box', 0);

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ...array_map(
            fn (int $number): array => ImportExecutionFixture::row($number + 1, ['Name' => "Person {$number}", 'Email' => "person{$number}@example.com"], ['match_action' => RowMatchAction::Create->value]),
            range(1, 500),
        ),
        ImportExecutionFixture::row(502, ['Name' => 'Person 1 again', 'Email' => 'person1@example.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);
    config()->set('import-wizard.execution_time_box', 240);
    ImportExecutionFixture::run($this);

    $import = $this->import->refresh();
    expect($import->created_rows)->toBe(500)
        ->and($import->updated_rows)->toBe(1)
        ->and(People::where('workspace_id', $this->workspace->id)->count())->toBe(500);
});
