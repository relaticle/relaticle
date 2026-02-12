<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(\Relaticle\ImportWizard\Commands\CleanupImportsCommand::class);

afterEach(function (): void {
    if (isset($this->stores)) {
        foreach ($this->stores as $store) {
            if (File::isDirectory($store->path())) {
                $store->destroy();
            }
        }
    }
});

function createTestStore(object $context, ImportStatus $status, string $updatedAt): ImportStore
{
    $store = ImportStore::create(
        teamId: 'team-1',
        userId: 'user-1',
        entityType: ImportEntityType::People,
        originalFilename: 'test.csv',
    );

    $meta = json_decode(File::get($store->metaPath()), true);
    $meta['status'] = $status->value;
    $meta['updated_at'] = $updatedAt;
    File::put($store->metaPath(), json_encode($meta, JSON_PRETTY_PRINT));

    $context->stores ??= [];
    $context->stores[] = $store;

    return $store;
}

it('deletes completed imports older than completed-hours threshold', function (): void {
    $store = createTestStore($this, ImportStatus::Completed, now()->subHours(3)->toIso8601String());

    expect(File::isDirectory($store->path()))->toBeTrue();

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($store->path()))->toBeFalse();
});

it('preserves recently completed imports', function (): void {
    $store = createTestStore($this, ImportStatus::Completed, now()->subMinutes(30)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($store->path()))->toBeTrue();
});

it('deletes stale in-progress imports older than hours threshold', function (): void {
    $store = createTestStore($this, ImportStatus::Mapping, now()->subHours(25)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($store->path()))->toBeFalse();
});

it('preserves active in-progress imports', function (): void {
    $store = createTestStore($this, ImportStatus::Mapping, now()->subHours(2)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($store->path()))->toBeTrue();
});

it('deletes directories without meta.json', function (): void {
    $path = storage_path('app/imports/orphaned-dir');
    File::ensureDirectoryExists($path);

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($path))->toBeFalse();
});

it('respects custom hours option', function (): void {
    $store = createTestStore($this, ImportStatus::Reviewing, now()->subHours(5)->toIso8601String());

    $this->artisan('import:cleanup --hours=48')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($store->path()))->toBeTrue();
});

it('handles missing imports directory', function (): void {
    $path = storage_path('app/imports');
    $backup = null;

    if (File::isDirectory($path)) {
        $backup = storage_path('app/imports_backup_test');
        File::moveDirectory($path, $backup);
    }

    try {
        $this->artisan('import:cleanup')
            ->expectsOutputToContain('No imports directory found')
            ->assertExitCode(0);
    } finally {
        if ($backup !== null) {
            File::moveDirectory($backup, $path);
        }
    }
});
