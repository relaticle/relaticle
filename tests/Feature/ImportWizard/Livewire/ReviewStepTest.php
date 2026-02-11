<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\ReviewFilter;
use Relaticle\ImportWizard\Enums\SortDirection;
use Relaticle\ImportWizard\Enums\SortField;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
use Relaticle\ImportWizard\Livewire\Steps\ReviewStep;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(ReviewStep::class);

beforeEach(function (): void {
    // Override the global Event::fake() from Pest.php to allow TeamCreated through,
    // so CreateTeamCustomFields listener runs and creates email/phone custom fields.
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();

    Filament::setTenant($this->team);

    $this->store = ImportStore::create(
        teamId: (string) $this->team->id,
        userId: (string) $this->user->id,
        entityType: ImportEntityType::People,
        originalFilename: 'test.csv',
    );

    $this->store->setHeaders(['Name', 'Emails']);
    $this->store->setColumnMappings([
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Emails', target: 'custom_fields_emails'),
    ]);

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

    $this->store->setRowCount(2);
    $this->store->setStatus(ImportStatus::Reviewing);
});

afterEach(function (): void {
    $this->store->destroy();
});

function mountReviewStep(object $context): \Livewire\Features\SupportTesting\Testable
{
    Bus::fake();

    return Livewire::test(ReviewStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
    ]);
}

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
        ->assertReturned(fn (array $errors) => isset($errors['not-an-email']) && ! isset($errors['valid@test.com']));

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

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->contains(fn ($job) => $job instanceof ResolveMatchesJob);
    });
});

it('includes __match_resolution key in batchIds', function (): void {
    $component = mountReviewStep($this);

    expect($component->get('batchIds'))->toHaveKey('__match_resolution');
});

it('blocks continueToPreview while match resolution is running', function (): void {
    $component = mountReviewStep($this);
    $component->set('batchIds', ['__match_resolution' => 'fake-batch-id']);

    $component->call('continueToPreview')
        ->assertNotDispatched('completed');
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
