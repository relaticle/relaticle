<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;
use Tests\Helpers\ImportExecutionFixture;

mutates(ValidateColumnJob::class, ColumnValidator::class, EntityLinkValidator::class);

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

function createValidationStore(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
    ImportEntityType $entityType = ImportEntityType::People,
): array {
    $import = Import::factory()->create([
        'workspace_id' => (string) $context->workspace->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => $entityType,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Reviewing,
        'total_rows' => count($rows),
        'headers' => $headers,
        'column_mappings' => collect($mappings)->map(fn (ColumnData $m) => $m->toArray())->all(),
    ]);

    $store = ImportStore::create($import->id);
    $store->query()->insert($rows);

    $store = ImportExecutionFixture::publish($store);

    $context->import = $import;
    $context->store = $store;

    return [$import, $store];
}

function makeValidationRow(int $rowNumber, array $rawData, array $overrides = []): array
{
    return array_merge([
        'row_number' => $rowNumber,
        'raw_data' => json_encode($rawData),
        'validation' => null,
        'corrections' => null,
        'skipped' => null,
        'match_action' => null,
        'matched_id' => null,
        'relationships' => null,
    ], $overrides);
}

it('writes RelationshipMatch create for Create entity links', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('Acme Corp');
});

it('writes RelationshipMatch existing when resolved to existing record', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'workspace_id' => $this->workspace->id,
    ]);

    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => (string) $company->id]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isExisting())->toBeTrue()
        ->and($row->relationships[0]->id)->toBe((string) $company->id);
});

it('skips relationship for MatchOnly when no match found', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toBeNull();
});

it('validates a corrected company link by its corrected value', function (): void {
    $company = Company::factory()->create(['workspace_id' => $this->workspace->id]);
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => 'missing-company'], [
            'corrections' => json_encode(['Company ID' => (string) $company->id]),
            'validation' => json_encode(['Company ID' => 'No company found']),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->hasValidationError('Company ID'))->toBeFalse()
        ->and($row->relationships->sole()->id)->toBe((string) $company->id);
});

it('writes no company link for an empty company cell', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => '']),
        makeValidationRow(3, ['Name' => 'Jane', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    expect($this->store->query()->where('row_number', 2)->first()->relationships)->toBeNull()
        ->and($this->store->query()->where('row_number', 3)->first()->relationships->sole()->name)->toBe('Acme Corp');
});

it('writes validation errors for unresolvable account owner entity link', function (): void {
    $column = ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner');

    createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => 'nonexistent@example.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();

    expect($row->hasValidationError('Owner Email'))->toBeTrue();
});

it('writes validation errors for entity link column with invalid id', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(1, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();

    expect($row->hasValidationError('Company ID'))->toBeTrue();
});

it('clears validation for corrected date fields', function (): void {
    $column = ColumnData::toField(source: 'Due Date', target: 'due_date');
    $column->importField = new ImportField(
        key: 'due_date',
        label: 'Due Date',
        rules: ['nullable', 'date'],
        type: FieldDataType::DATE,
    );

    createValidationStore($this, ['Name', 'Due Date'], [
        makeValidationRow(1, ['Name' => 'Task 1', 'Due Date' => 'not-a-date'], [
            'validation' => json_encode(['Due Date' => 'Invalid date format']),
            'corrections' => json_encode(['Due Date' => '2024-01-15']),
        ]),
        makeValidationRow(2, ['Name' => 'Task 2', 'Due Date' => 'also-invalid'], [
            'validation' => json_encode(['Due Date' => 'Invalid date format']),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Task);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $correctedRow = $this->store->query()->where('row_number', 1)->first();
    $uncorrectedRow = $this->store->query()->where('row_number', 2)->first();

    expect($correctedRow->hasValidationError('Due Date'))->toBeFalse();
    expect($uncorrectedRow->hasValidationError('Due Date'))->toBeTrue();
});

it('skips validation when import does not exist', function (): void {
    $column = ColumnData::toField(source: 'Name', target: 'name');

    $job = new ValidateColumnJob('nonexistent-import-id', $column);

    try {
        $job->handle();
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (ModelNotFoundException $e) {
        expect($e->getModel())->toBe(Import::class);
    }
});

it('skips validation when all values are empty', function (): void {
    $column = ColumnData::toField(source: 'Owner Email', target: 'account_owner_email');

    createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => '']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();
    expect($row->validation)->toBeNull();
});

it('appends to existing relationships array without overwriting', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    $existingRelationship = [
        'relationship' => 'contact',
        'action' => 'create',
        'name' => 'Jane Doe',
    ];

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$existingRelationship]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toHaveCount(2)
        ->and($row->relationships[0]->relationship)->toBe('contact')
        ->and($row->relationships[1]->relationship)->toBe('company');
});

it('validates color picker hex format', function (): void {
    $column = ColumnData::toField(source: 'Color', target: 'custom_fields_brand_color');
    $column->importField = new ImportField(
        key: 'custom_fields_brand_color',
        label: 'Brand Color',
        rules: ['regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        isCustomField: true,
        type: FieldDataType::STRING,
    );

    createValidationStore($this, ['Name', 'Color'], [
        makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        makeValidationRow(2, ['Name' => 'Jane', 'Color' => '#ff5733']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $invalidRow = $this->store->query()->where('row_number', 1)->first();
    $validRow = $this->store->query()->where('row_number', 2)->first();

    expect($invalidRow->hasValidationError('Color'))->toBeTrue()
        ->and($validRow->hasValidationError('Color'))->toBeFalse();
});

// --- Entity Link Format Validation Tests ---

function ensureCustomFieldExists(object $context, string $code, string $type, string $entityType = 'people'): CustomField
{
    $existing = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $context->workspace->id)
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    return CustomField::forceCreate([
        'tenant_id' => $context->workspace->id,
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'type' => $type,
        'entity_type' => $entityType,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData,
    ]);
}

it('rejects invalid domain format in entity link and prevents relationship creation', function (): void {
    ensureCustomFieldExists($this, 'domains', 'link', 'company');

    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'GlobalHealth Inc']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Company'))->toBeTrue()
        ->and($row->relationships)->toBeNull();
});

it('accepts valid domain format in entity link and creates relationship', function (): void {
    ensureCustomFieldExists($this, 'domains', 'link', 'company');

    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'globalhealth.io']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('globalhealth.io');
});

it('rejects invalid email format in entity link', function (): void {
    ensureCustomFieldExists($this, 'emails', 'email');

    $column = ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact');

    createValidationStore($this, ['Name', 'Contact'], [
        makeValidationRow(2, ['Name' => 'Deal', 'Contact' => 'not-an-email']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Opportunity);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Contact'))->toBeTrue()
        ->and($row->relationships)->toBeNull();
});

it('accepts valid email format in entity link and creates relationship', function (): void {
    ensureCustomFieldExists($this, 'emails', 'email');

    $column = ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact');

    createValidationStore($this, ['Name', 'Contact'], [
        makeValidationRow(2, ['Name' => 'Deal', 'Contact' => 'john@example.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Opportunity);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('john@example.com');
});

it('skips format validation for name matcher on entity link', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Company'))->toBeFalse()
        ->and($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1);
});

function makeBrandColorColumn(): ColumnData
{
    $column = ColumnData::toField(source: 'Color', target: 'custom_fields_brand_color');
    $column->importField = new ImportField(
        key: 'custom_fields_brand_color',
        label: 'Brand Color',
        rules: ['regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        isCustomField: true,
        type: FieldDataType::STRING,
    );

    return $column;
}

describe('on a remote store disk', function (): void {
    beforeEach(function (): void {
        useRemoteImportStore();
    });

    it('writes validation errors into the remote store', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
            makeValidationRow(2, ['Name' => 'Jane', 'Color' => '#ff5733']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        (new ValidateColumnJob($this->import->id, $column))->handle();

        $rows = ImportStore::forRead($this->import->id)->query()->orderBy('row_number')->get();

        expect($rows[0]->hasValidationError('Color'))->toBeTrue()
            ->and($rows[1]->hasValidationError('Color'))->toBeFalse();
    });

    it('leaves validation off a row whose value was corrected', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color'], [
                'corrections' => json_encode(['Color' => '#ff5733']),
            ]),
            makeValidationRow(2, ['Name' => 'Jane', 'Color' => 'not-a-color']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        (new ValidateColumnJob($this->import->id, $column))->handle();

        $rows = ImportStore::forRead($this->import->id)->query()->orderBy('row_number')->get();

        expect($rows[0]->validation)->toBeNull()
            ->and($rows[1]->hasValidationError('Color'))->toBeTrue();
    });

    it('writes entity link relationships into the remote store', function (): void {
        $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

        createValidationStore($this, ['Name', 'Company'], [
            makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        (new ValidateColumnJob($this->import->id, $column))->handle();

        $row = ImportStore::forRead($this->import->id)->query()->where('row_number', 2)->first();

        expect($row->relationships->sole()->name)->toBe('Acme Corp');
    });

    it('fails with a lock timeout instead of writing when another writer holds the store', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        $held = Cache::lock(importStoreLockName($this->import->id), 150);
        $held->get();
        config()->set('import-wizard.store.lock.wait.job', 0);

        expect(fn () => (new ValidateColumnJob($this->import->id, $column))->handle())
            ->toThrow(ImportStoreException::class);

        $held->release();

        expect(ImportStore::forRead($this->import->id)->query()->first()->validation)->toBeNull();
    });

    it('does not upload the store when its batch is cancelled before it writes', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        $remoteFile = Storage::disk('s3')->getConfig()['root']."/imports/{$this->import->id}.sqlite";
        touch($remoteFile, time() - 3600);
        clearstatcache();
        $modifiedAt = Storage::disk('s3')->lastModified("imports/{$this->import->id}.sqlite");

        [$job] = (new ValidateColumnJob($this->import->id, $column))->withFakeBatch();

        Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class, QueryExecuted::class]);

        DB::listen(function (QueryExecuted $query) use ($job): void {
            if (str_contains($query->sql, 'DISTINCT')) {
                $job->batch()->cancel();
            }
        });

        $job->handle();

        clearstatcache();
        expect(Storage::disk('s3')->lastModified("imports/{$this->import->id}.sqlite"))->toBe($modifiedAt);
    });

    it('does not upload the store when a correction landed before its write and matches no row', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        $remoteFile = Storage::disk('s3')->getConfig()['root']."/imports/{$this->import->id}.sqlite";
        $corrected = false;
        $modifiedAt = 0;

        Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class, QueryExecuted::class]);

        DB::listen(function (QueryExecuted $query) use (&$corrected, &$modifiedAt, $remoteFile): void {
            if ($corrected || ! str_contains($query->sql, 'DISTINCT')) {
                return;
            }

            $corrected = true;
            ImportStore::withWriteLock($this->import->id, fn (ImportStore $store): int => $store->query()->update(['corrections' => json_encode(['Color' => '#ff5733'])]));
            touch($remoteFile, time() - 3600);
            clearstatcache();
            $modifiedAt = Storage::disk('s3')->lastModified("imports/{$this->import->id}.sqlite");
        });

        (new ValidateColumnJob($this->import->id, $column))->handle();

        clearstatcache();
        expect($corrected)->toBeTrue()
            ->and(Storage::disk('s3')->lastModified("imports/{$this->import->id}.sqlite"))->toBe($modifiedAt)
            ->and(ImportStore::forRead($this->import->id)->query()->first()->validation)->toBeNull();
    });

    it('returns quietly when the store was deleted', function (): void {
        $column = makeBrandColorColumn();

        createValidationStore($this, ['Name', 'Color'], [
            makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        ], [
            ColumnData::toField(source: 'Name', target: 'name'),
            $column,
        ]);

        ImportStore::delete($this->import->id);

        (new ValidateColumnJob($this->import->id, $column))->handle();

        expect(ImportStore::exists($this->import->id))->toBeFalse();
    });
});

it('writes nothing when its batch is cancelled while it validates', function (): void {
    $column = makeBrandColorColumn();

    createValidationStore($this, ['Name', 'Color'], [
        makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    [$job] = (new ValidateColumnJob($this->import->id, $column))->withFakeBatch();

    Event::fake()->except([WorkspaceCreated::class, Authenticated::class, TenantSet::class, QueryExecuted::class]);

    DB::listen(function (QueryExecuted $query) use ($job): void {
        if (str_contains($query->sql, 'DISTINCT')) {
            $job->batch()->cancel();
        }
    });

    $job->handle();

    expect($job->batch()->cancelled())->toBeTrue()
        ->and(ImportStore::forRead($this->import->id)->query()->first()->validation)->toBeNull();
});
