<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Livewire\Steps\UploadStep;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(UploadStep::class);

function makeCsvFile(string $content, string $name = 'test.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);

    $this->createdStoreIds = [];
});

afterEach(function (): void {
    foreach ($this->createdStoreIds as $storeId) {
        ImportStore::load($storeId)?->destroy();
    }
});

function mountUploadStep(object $context, ?string $storeId = null): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(UploadStep::class, [
        'entityType' => ImportEntityType::People,
        'storeId' => $storeId,
    ]);
}

// ─── Mount ──────────────────────────────────────────────────────────────────

it('renders upload form on mount', function (): void {
    $component = mountUploadStep($this);

    $component->assertOk();

    expect($component->get('isParsed'))->toBeFalse()
        ->and($component->get('headers'))->toBe([])
        ->and($component->get('rowCount'))->toBe(0);
});

// ─── File Parsing ───────────────────────────────────────────────────────────

it('parses valid CSV and shows preview', function (): void {
    $csv = makeCsvFile("Name,Email,Phone\nJohn,john@test.com,555-1234\nJane,jane@test.com,555-5678\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue()
        ->and($component->get('headers'))->toBe(['Name', 'Email', 'Phone'])
        ->and($component->get('rowCount'))->toBe(2);
});

it('rejects non-CSV file type', function (): void {
    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $file);

    $component->assertHasErrors(['uploadedFile']);
});

it('rejects empty CSV with no headers', function (): void {
    $csv = makeCsvFile('');

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $component->assertHasErrors(['uploadedFile' => 'CSV file is empty']);
});

it('rejects CSV with headers but no data rows', function (): void {
    $csv = makeCsvFile("Name,Email,Phone\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $component->assertHasErrors(['uploadedFile' => 'CSV file has no data rows']);
});

it('fills blank headers with Column_N', function (): void {
    $csv = makeCsvFile("Name,,Email\nJohn,value,john@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue()
        ->and($component->get('headers'))->toBe(['Name', 'Column_2', 'Email']);
});

it('rejects duplicate column headers', function (): void {
    $csv = makeCsvFile("Name,Name,Email\nJohn,Doe,john@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $component->assertHasErrors(['uploadedFile' => 'Duplicate column names found.']);
});

it('normalizes rows with fewer columns than headers', function (): void {
    $csv = makeCsvFile("Name,Email,Phone\nJohn\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue()
        ->and($component->get('rowCount'))->toBe(1);
});

// ─── Continue to Mapping ────────────────────────────────────────────────────

it('continueToMapping creates ImportStore with rows', function (): void {
    $csv = makeCsvFile("Name,Email\nJohn,john@test.com\nJane,jane@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);
    $component->call('continueToMapping');

    $component->assertDispatched('completed', function (string $event, array $params): bool {
        $this->createdStoreIds[] = $params['storeId'];

        $import = Import::find($params['storeId']);
        $store = ImportStore::load($params['storeId']);

        expect($import)->not->toBeNull()
            ->and($store)->not->toBeNull()
            ->and($import->headers)->toBe(['Name', 'Email'])
            ->and($import->total_rows)->toBe(2)
            ->and($import->status)->toBe(ImportStatus::Mapping);

        $rows = $store->query()->get();
        expect($rows)->toHaveCount(2);

        $firstRow = $rows->first();
        expect($firstRow->raw_data->get('Name'))->toBe('John')
            ->and($firstRow->raw_data->get('Email'))->toBe('john@test.com');

        return true;
    });
});

it('continueToMapping dispatches completed event with correct params', function (): void {
    $csv = makeCsvFile("Name,Email,Phone\nJohn,john@test.com,555\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);
    $component->call('continueToMapping');

    $component->assertDispatched('completed', function (string $event, array $params): bool {
        $this->createdStoreIds[] = $params['storeId'];

        expect($params['rowCount'])->toBe(1)
            ->and($params['columnCount'])->toBe(3)
            ->and($params['storeId'])->toBeString()->not->toBeEmpty();

        return true;
    });
});

it('continueToMapping fails when file is missing', function (): void {
    $component = mountUploadStep($this);

    $component->set('isParsed', true);
    $component->call('continueToMapping');

    $component->assertHasErrors(['uploadedFile' => 'File no longer available. Please re-upload.']);
    expect($component->get('isParsed'))->toBeFalse();
});

// ─── Remove File ────────────────────────────────────────────────────────────

it('removeFile resets state', function (): void {
    $csv = makeCsvFile("Name,Email\nJohn,john@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue();

    $component->call('removeFile');

    expect($component->get('isParsed'))->toBeFalse()
        ->and($component->get('headers'))->toBe([])
        ->and($component->get('rowCount'))->toBe(0);
});
