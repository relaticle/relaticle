<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Relaticle\ImportWizard\Livewire\Steps\UploadStep;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(UploadStep::class);

function makeCsvFile(string $content, string $name = 'test.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

beforeEach(function (): void {
    Event::fake()->except([WorkspaceCreated::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    Filament::setTenant($this->workspace);

    $this->createdStoreIds = [];
});

afterEach(function (): void {
    foreach ($this->createdStoreIds as $storeId) {
        ImportStore::delete($storeId);
    }
});

function mountUploadStep(object $context, ?string $storeId = null): Testable
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

        $import = Import::query()->find($params['storeId']);
        $store = ImportStore::forRead($params['storeId']);

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

it('rejects a csv that is not utf-8 with a message the user can act on', function (): void {
    Exceptions::fake();

    $csv = makeCsvFile("Full Name,Email\nAda,ada@test.com\nJos\xE9 Mu\xF1oz,jose@test.com\nGrace,grace@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $message = 'Row 3 is not UTF-8 text. In Excel, choose File > Save As > CSV UTF-8, then upload the file again.';

    $component->assertHasErrors(['uploadedFile' => $message]);
    Exceptions::assertNothingReported();
});

it('reports the physical line when the file has empty lines before a non-utf-8 row', function (): void {
    $csv = makeCsvFile("Full Name,Email\nAda,a@x.com\n\nJos\xE9,j@x.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $component->assertHasErrors(['uploadedFile' => 'Row 4 is not UTF-8 text. In Excel, choose File > Save As > CSV UTF-8, then upload the file again.']);
});

it('counts the file row when blank lines come before a non-utf-8 row', function (): void {
    $csv = makeCsvFile("Full Name,Email\nAda,ada@test.com\n,\nJos\xE9,jose@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    $component->assertHasErrors(['uploadedFile' => 'Row 4 is not UTF-8 text. In Excel, choose File > Save As > CSV UTF-8, then upload the file again.']);
});

it('imports a utf-8 csv with accented and arabic names', function (): void {
    $csv = makeCsvFile("Full Name,Email\nJosé Núñez,jose@test.com\nمحمد العمري,mohammed@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);
    $component->call('continueToMapping');

    $component->assertHasNoErrors();
    $component->assertDispatched('completed', function (string $event, array $params): bool {
        $this->createdStoreIds[] = $params['storeId'];

        expect($params['rowCount'])->toBe(2);

        $names = ImportStore::forRead($params['storeId'])
            ->query()
            ->orderBy('row_number')
            ->get()
            ->map(fn (object $row): string => $row->raw_data['Full Name'])
            ->all();

        expect($names)->toBe(['José Núñez', 'محمد العمري']);

        return true;
    });
});

it('continueToMapping skips blank, comma-only, whitespace-only rows and survives bom and crlf', function (): void {
    $csv = makeCsvFile("\xEF\xBB\xBFName,Email\r\n\r\n,\r\n  ,  \r\nAda,ada@test.com\r\n,,\r\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('rowCount'))->toBe(1);

    $component->call('continueToMapping');

    $component->assertHasNoErrors();
    $component->assertDispatched('completed', function (string $event, array $params): bool {
        $this->createdStoreIds[] = $params['storeId'];

        expect($params['rowCount'])->toBe(1);

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

it('parses and loads a csv when temporary uploads live on a disk with no local paths', function (): void {
    fakeDiskWithoutLocalPaths(FileUploadConfiguration::disk());
    $csv = makeCsvFile("Name,Email\nJohn,john@test.com\nJane,jane@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue()
        ->and($component->get('rowCount'))->toBe(2);

    $component->call('continueToMapping')->assertHasNoErrors();

    $import = Import::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
    $this->createdStoreIds[] = $import->id;

    expect($import->total_rows)->toBe(2);
});

it('reports a csv whose local copy cannot be written instead of parsing it as empty', function (): void {
    Exceptions::fake();
    $fake = Storage::fake(FileUploadConfiguration::disk());
    Storage::set(FileUploadConfiguration::disk(), new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public function readStream(mixed $path): mixed
        {
            return fopen('php://output', 'w');
        }
    });

    $component = mountUploadStep($this);
    $component->set('uploadedFile', makeCsvFile("Name,Email\nJohn,john@test.com\n"));

    expect($component->get('isParsed'))->toBeFalse();
    $component->assertHasErrors(['uploadedFile' => 'Unable to process this file. Please check the format and try again.']);
    Exceptions::assertReported(RuntimeException::class);
});

describe('on a remote store disk', function (): void {
    beforeEach(function (): void {
        useRemoteImportStore();
    });

    it('keeps the uploaded rows on the store disk and nothing under storage', function (): void {
        mountUploadStep($this)
            ->set('uploadedFile', makeCsvFile("Name,Email\nAda,ada@example.com\nGrace,grace@example.com\n"))
            ->call('continueToMapping')
            ->assertHasNoErrors();

        $import = Import::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
        $this->createdStoreIds[] = $import->id;

        Storage::disk('s3')->assertExists("imports/{$import->id}.sqlite");

        expect(File::exists(config('import-wizard.storage_path')."/{$import->id}"))->toBeFalse()
            ->and(ImportStore::forRead($import->id)?->query()->count())->toBe(2);
    });

    it('removes the remote file and the import when the upload to the store disk fails', function (): void {
        Exceptions::fake();
        $fake = Storage::disk('s3');
        Storage::set('s3', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function writeStream(mixed $path, mixed $resource, array $options = []): bool
            {
                return false;
            }
        });

        $component = mountUploadStep($this)
            ->set('uploadedFile', makeCsvFile("Name\nAda\n"))
            ->call('continueToMapping');

        $component->assertHasErrors(['uploadedFile' => 'Unable to process this file. Please try again or use a different file.']);
        Exceptions::assertReported(ImportStoreException::class);

        expect(Import::query()->where('workspace_id', $this->workspace->getKey())->exists())->toBeFalse()
            ->and(Storage::disk('s3')->allFiles('imports'))->toBe([]);
    });
});
