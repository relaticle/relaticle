<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\ReviewFilter;
use Relaticle\ImportWizard\Enums\SortDirection;
use Relaticle\ImportWizard\Enums\SortField;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Livewire\Steps\PreviewStep;
use Relaticle\ImportWizard\Livewire\Steps\ReviewStep;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(ReviewStep::class, ValidateColumnJob::class);

beforeEach(function (): void {
    // Override the global Event::fake() from Pest.php to allow WorkspaceCreated through,
    // so CreateWorkspaceCustomFields listener runs and creates email/phone custom fields.
    Event::fake()->except([WorkspaceCreated::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    Filament::setTenant($this->workspace);

    $this->import = Import::factory()->create([
        'workspace_id' => (string) $this->workspace->id,
        'user_id' => (string) $this->user->id,
        'entity_type' => ImportEntityType::People,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Reviewing,
        'total_rows' => 2,
        'headers' => ['Name', 'Emails'],
        'column_mappings' => collect([
            ColumnData::toField(source: 'Name', target: 'name'),
            ColumnData::toField(source: 'Emails', target: 'custom_fields_emails'),
        ])->map(fn (ColumnData $m): array => $m->toArray())->all(),
    ]);

    $this->store = ImportStore::create($this->import->id);

    $this->store->query()->insert([
        [
            'row_number' => 2,
            'raw_data' => json_encode(['Name' => 'John', 'Emails' => 'john@test.com']),
            'validation' => null,
            'corrections' => null,
            'skipped' => null,
            'match_action' => null,
            'matched_id' => null,
            'relationships' => null,
        ],
        [
            'row_number' => 3,
            'raw_data' => json_encode(['Name' => 'Jane', 'Emails' => 'jane@test.com, admin@test.com']),
            'validation' => null,
            'corrections' => null,
            'skipped' => null,
            'match_action' => null,
            'matched_id' => null,
            'relationships' => null,
        ],
    ]);

    Cache::forget("import-{$this->import->id}-validation");
});

afterEach(function (): void {
    $this->store->destroy();
    $this->import->delete();
});

function mountReviewStep(object $context): Testable
{
    Bus::fake();

    return Livewire::test(ReviewStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
    ]);
}

function reviewCompanyLink(object $context, string $companyId): Testable
{
    $context->import->update([
        'headers' => ['Name', 'Company'],
        'column_mappings' => [
            ColumnData::toField(source: 'Name', target: 'name')->toArray(),
            ColumnData::toEntityLink(source: 'Company', matcherKey: 'id', entityLinkKey: 'company')->toArray(),
        ],
    ]);
    $context->store->query()->where('row_number', 2)->update([
        'raw_data' => json_encode(['Name' => 'Maya Chen', 'Company' => $companyId]),
    ]);
    $context->store->query()->where('row_number', 3)->update([
        'raw_data' => json_encode(['Name' => 'Leo Grant', 'Company' => $context->controlCompany->id]),
    ]);

    return Livewire::test(ReviewStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
    ])->call('checkProgress')->call('selectColumn', 'Company');
}

it('keeps company links consistent through Review edits', function (string $scenario, string $expectedCompany): void {
    $this->originalCompany = Company::factory()->create(['name' => 'Northline Studio', 'workspace_id' => $this->workspace->id]);
    $this->controlCompany = Company::factory()->create(['name' => 'Cedar Labs', 'workspace_id' => $this->workspace->id]);
    $replacement = Company::factory()->create(['name' => 'Harbor Works', 'workspace_id' => $this->workspace->id]);
    $rawValue = $scenario === 'invalid reference' ? 'missing-company' : (string) $this->originalCompany->id;
    $component = reviewCompanyLink($this, $rawValue);

    $component->call('updateMappedValue', $rawValue, (string) $replacement->id)
        ->call('checkProgress');

    if ($scenario === 'undo') {
        $component->call('undoCorrection', $rawValue)->call('checkProgress');
    }

    if (in_array($scenario, ['skip', 'unskip'], true)) {
        $component->call('skipValue', $rawValue)->call('checkProgress');
    }

    if ($scenario === 'unskip') {
        $component->call('unskipValue', $rawValue)->call('checkProgress');
    }

    if ($scenario === 'revalidation') {
        Cache::forget("import-{$this->import->id}-validation");
        $component = Livewire::test(ReviewStep::class, [
            'storeId' => $this->store->id(),
            'entityType' => ImportEntityType::People,
        ])->call('checkProgress');
    }

    $component->call('continueToPreview')->assertDispatched('completed');

    $expectedId = match ($expectedCompany) {
        'Harbor Works' => (string) $replacement->id,
        'Northline Studio' => (string) $this->originalCompany->id,
        'none' => null,
    };
    $row = $this->store->query()->where('row_number', 2)->firstOrFail();
    expect($row->relationships?->count() ?? 0)->toBe($expectedId === null ? 0 : 1)
        ->and($row->relationships?->first()?->id)->toBe($expectedId);

    Livewire::test(PreviewStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::People,
    ])->call('startImport');

    expect(People::query()->where('name', 'Maya Chen')->sole()->company_id)->toBe($expectedId)
        ->and(People::query()->where('name', 'Leo Grant')->sole()->company_id)->toBe((string) $this->controlCompany->id)
        ->and($this->import->fresh()->failed_rows)->toBe(0);
})->with([
    'correct invalid reference' => ['invalid reference', 'Harbor Works'],
    'replace valid reference' => ['replacement', 'Harbor Works'],
    'undo correction' => ['undo', 'Northline Studio'],
    'skip corrected reference' => ['skip', 'none'],
    'restore skipped reference' => ['unskip', 'Northline Studio'],
    'revalidate corrected reference' => ['revalidation', 'Harbor Works'],
]);

it('preserves company identity matching when its name mapping is corrected', function (): void {
    $company = Company::factory()->create(['name' => 'Northline Studio', 'workspace_id' => $this->workspace->id]);
    $this->import->update([
        'total_rows' => 1,
        'headers' => ['Name', 'Company ID', 'Company Name'],
        'column_mappings' => [
            ColumnData::toField(source: 'Name', target: 'name')->toArray(),
            ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company')->toArray(),
            ColumnData::toEntityLink(source: 'Company Name', matcherKey: 'name', entityLinkKey: 'company')->toArray(),
        ],
    ]);
    $this->store->query()->where('row_number', 3)->delete();
    $this->store->query()->where('row_number', 2)->update([
        'raw_data' => json_encode(['Name' => 'Maya Chen', 'Company ID' => $company->id, 'Company Name' => 'Northline']),
    ]);

    Livewire::test(ReviewStep::class, ['storeId' => $this->store->id(), 'entityType' => ImportEntityType::People])
        ->call('checkProgress')
        ->call('selectColumn', 'Company Name')
        ->call('updateMappedValue', 'Northline', 'Northline Studio')
        ->call('checkProgress')
        ->call('continueToPreview');

    Livewire::test(PreviewStep::class, ['storeId' => $this->store->id(), 'entityType' => ImportEntityType::People])
        ->call('startImport');

    expect(People::query()->where('name', 'Maya Chen')->sole()->company_id)->toBe((string) $company->id)
        ->and(Company::query()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

it('blocks Preview after failed relationship revalidation until retry succeeds', function (): void {
    $this->originalCompany = Company::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->controlCompany = Company::factory()->create(['workspace_id' => $this->workspace->id]);
    $component = reviewCompanyLink($this, 'missing-company');
    Queue::fake([ValidateColumnJob::class]);

    $component->call('updateMappedValue', 'missing-company', (string) $this->originalCompany->id);
    $batch = Bus::findBatch($component->get('batchIds.Company'));
    $batch->recordFailedJob('failed-validation', new RuntimeException('Validation worker failed'));

    $component->call('checkProgress')
        ->call('continueToPreview')
        ->assertNotDispatched('completed');

    $component = Livewire::test(ReviewStep::class, ['storeId' => $this->store->id(), 'entityType' => ImportEntityType::People]);
    $component->call('continueToPreview')->assertNotDispatched('completed');
    $component->call('retryFailedValidation');

    $retryId = $component->get('batchIds.Company');
    $column = $this->import->getColumnMapping('Company');
    new ValidateColumnJob($this->import->id, $column)->withBatchId($retryId)->handle();
    Bus::findBatch($retryId)->recordSuccessfulJob('retried-validation');

    $component->call('checkProgress')->call('continueToPreview')->assertDispatched('completed');
    expect($this->store->query()->where('row_number', 2)->firstOrFail()->relationships->first()->id)
        ->toBe((string) $this->originalCompany->id);

    Livewire::test(PreviewStep::class, ['storeId' => $this->store->id(), 'entityType' => ImportEntityType::People])
        ->assertSee((string) $this->originalCompany->id)
        ->assertDontSee('missing-company');
});

it('renders with correct columns', function (): void {
    $component = mountReviewStep($this);

    $component->assertOk();

    $columns = $component->get('columns');
    expect($columns)->toHaveCount(2)
        ->and($columns->pluck('source')->all())->toBe(['Name', 'Emails']);
});

it('selects first column by default on mount', function (): void {
    $component = mountReviewStep($this);

    expect($component->get('selectedColumn.source'))->toBe('Name');
});

it('changes selected column via selectColumn', function (): void {
    $component = mountReviewStep($this);

    $component->call('selectColumn', 'Emails');

    expect($component->get('selectedColumn.source'))->toBe('Emails');
});

it('stores text correction in SQLite via updateMappedValue', function (): void {
    $component = mountReviewStep($this);

    $component->call('updateMappedValue', 'John', 'Johnny');

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->corrections->get('Name'))->toBe('Johnny');
});

it('stores validation error for invalid text correction', function (): void {
    $component = mountReviewStep($this);

    $component->call('selectColumn', 'Emails');
    $component->call('updateMappedValue', 'john@test.com', 'not-an-email');

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->corrections->get('Emails'))->toBe('not-an-email')
        ->and($row->validation->get('Emails'))->not->toBeNull();
});

it('returns empty errors for valid emails in multi-value field', function (): void {
    $component = mountReviewStep($this);

    $component->call('selectColumn', 'Emails');
    $component
        ->call('updateMappedValue', 'john@test.com', 'valid@test.com, another@test.com')
        ->assertReturned([]);
});

it('returns per-item errors for invalid emails in multi-value field', function (): void {
    $component = mountReviewStep($this);

    $component->call('selectColumn', 'Emails');
    $component
        ->call('updateMappedValue', 'john@test.com', 'valid@test.com, not-an-email')
        ->assertReturned(fn (array $errors): bool => isset($errors['not-an-email']) && ! isset($errors['valid@test.com']));

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->corrections->get('Emails'))->toBe('valid@test.com, not-an-email')
        ->and($row->validation->get('Emails'))->not->toBeNull();
});

it('marks value as skipped via skipValue', function (): void {
    $component = mountReviewStep($this);

    $component->call('skipValue', 'John');

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->skipped->get('Name'))->toBeTrue();
});

it('removes skip flag via unskipValue', function (): void {
    $component = mountReviewStep($this);

    $component->call('skipValue', 'John');
    $component->call('unskipValue', 'John');

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->skipped?->get('Name'))->toBeNull();
});

it('removes correction and re-validates raw value via undoCorrection', function (): void {
    $component = mountReviewStep($this);

    $component->call('updateMappedValue', 'John', 'Johnny');
    $component->call('undoCorrection', 'John');

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->corrections?->get('Name'))->toBeNull();
});

it('setFilter changes filter and resets pagination', function (): void {
    $component = mountReviewStep($this);

    $component->call('setFilter', 'needs_review');

    expect($component->get('filter'))->toBe(ReviewFilter::NeedsReview);
});

it('clearFilters resets search and filter', function (): void {
    $component = mountReviewStep($this);

    $component->set('search', 'John');
    $component->call('setFilter', 'needs_review');
    $component->call('clearFilters');

    expect($component->get('search'))->toBe('')
        ->and($component->get('filter'))->toBe(ReviewFilter::All);
});

it('setSortField changes sort', function (): void {
    $component = mountReviewStep($this);

    $component->call('setSortField', 'raw_value');

    expect($component->get('sortField'))->toBe(SortField::Value);
});

it('setSortDirection changes direction', function (): void {
    $component = mountReviewStep($this);

    $component->call('setSortDirection', 'asc');

    expect($component->get('sortDirection'))->toBe(SortDirection::Asc);
});

it('updatedSearch applies and component renders without error', function (): void {
    $component = mountReviewStep($this);

    $component->set('search', 'John');

    $component->assertOk();
    expect($component->get('search'))->toBe('John');
});

it('columnErrorStatuses reflects validation state', function (): void {
    $jsonPath = '$.Name';
    $this->store->connection()->statement("
        UPDATE import_rows
        SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
        WHERE json_extract(raw_data, ?) = ?
    ", [$jsonPath, 'Required field', $jsonPath, 'John']);

    $component = mountReviewStep($this);

    $statuses = $component->get('columnErrorStatuses');
    expect($statuses['Name'])->toBeTrue();
});

it('dispatches completed event when continueToPreview is called', function (): void {
    $component = mountReviewStep($this);
    $component->set('batchIds', []);

    $component->call('continueToPreview')
        ->assertDispatched('completed');
});

it('does not dispatch completed while validation batches are still running', function (): void {
    $component = mountReviewStep($this);
    $component->set('batchIds', ['Name' => 'fake-batch-id']);

    $component->call('continueToPreview')
        ->assertNotDispatched('completed');
});

it('dispatches completed even when unresolved validation errors exist', function (): void {
    $jsonPath = '$.Name';
    $this->store->connection()->statement("
        UPDATE import_rows
        SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
        WHERE json_extract(raw_data, ?) = ?
    ", [$jsonPath, 'Required field', $jsonPath, 'John']);

    $component = mountReviewStep($this);
    $component->set('batchIds', []);

    $component->call('continueToPreview')
        ->assertDispatched('completed');
});

it('dispatches ResolveMatchesJob batch on mount', function (): void {
    $component = mountReviewStep($this);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->contains(fn (object $job): bool => $job instanceof ResolveMatchesJob));
});

it('includes __match_resolution key in batchIds', function (): void {
    $component = mountReviewStep($this);

    expect($component->get('batchIds'))->toHaveKey('__match_resolution');
});

it('allows continueToPreview while only match resolution is running', function (): void {
    $component = mountReviewStep($this);
    $component->set('batchIds', ['__match_resolution' => 'fake-batch-id']);

    $component->call('continueToPreview')
        ->assertDispatched('completed');

    $cachedBatchId = Cache::get("import-{$this->store->id()}-match-resolution-batch");

    expect($cachedBatchId)->toBe('fake-batch-id');
});

it('clears relationships column on mount', function (): void {
    $this->store->connection()->statement("
        UPDATE import_rows SET relationships = '[{\"relationship\":\"company\",\"action\":\"create\",\"name\":\"Stale\"}]'
    ");

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull();

    mountReviewStep($this);

    $freshRow = $this->store->query()->where('row_number', 2)->first();
    expect($freshRow->relationships)->toBeNull();
});

it('skips dispatching batches when cache hash matches', function (): void {
    mountReviewStep($this);

    Bus::assertBatched(fn (): true => true);

    Bus::fake();

    mountReviewStep($this);

    Bus::assertNothingBatched();
});

it('dispatches new batches when mappings hash changes', function (): void {
    mountReviewStep($this);

    Bus::assertBatched(fn (): true => true);

    $this->import->update([
        'column_mappings' => collect([
            ColumnData::toField(source: 'Name', target: 'name'),
        ])->map(fn (ColumnData $m): array => $m->toArray())->all(),
    ]);

    Bus::fake();

    mountReviewStep($this);

    Bus::assertBatched(fn (): true => true);
});
