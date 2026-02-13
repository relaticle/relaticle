<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(\Relaticle\ImportWizard\Commands\CleanupImportsCommand::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->imports = [];
});

afterEach(function (): void {
    foreach ($this->imports as $import) {
        ImportStore::load($import->id)?->destroy();
        $import->delete();
    }
});

function createTestImport(object $context, ImportStatus $status, string $updatedAt): Import
{
    $import = Import::create([
        'team_id' => (string) $context->team->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => ImportEntityType::People,
        'file_name' => 'test.csv',
        'status' => $status,
        'total_rows' => 0,
        'headers' => [],
    ]);

    $import->updated_at = $updatedAt;
    $import->saveQuietly();

    $store = ImportStore::create($import->id);

    $context->imports[] = $import;

    return $import;
}

it('deletes completed import files older than completed-hours threshold', function (): void {
    $import = createTestImport($this, ImportStatus::Completed, now()->subHours(3)->toIso8601String());

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($storePath))->toBeFalse();
    expect(Import::find($import->id))->not->toBeNull();
});

it('preserves recently completed import files', function (): void {
    $import = createTestImport($this, ImportStatus::Completed, now()->subMinutes(30)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});

it('deletes abandoned imports older than hours threshold', function (): void {
    $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(25)->toIso8601String());

    $storePath = storage_path("app/imports/{$import->id}");

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($storePath))->toBeFalse();
    expect(Import::find($import->id))->toBeNull();
});

it('preserves active in-progress imports', function (): void {
    $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(2)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});

it('deletes orphaned directories without DB records', function (): void {
    $path = storage_path('app/imports/orphaned-dir');
    File::ensureDirectoryExists($path);

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($path))->toBeFalse();
});

it('respects custom hours option', function (): void {
    $import = createTestImport($this, ImportStatus::Reviewing, now()->subHours(5)->toIso8601String());

    $this->artisan('import:cleanup --hours=48')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});
