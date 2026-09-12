<?php

declare(strict_types=1);

use App\Console\Commands\PurgePendingUploadsCommand;
use App\Enums\MediaCollection;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(PurgePendingUploadsCommand::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->team = User::factory()->withPersonalTeam()->create()->personalTeam();
});

it('removes pending media and temp files older than a day, keeps the rest', function (): void {
    $this->travelTo(now()->subHours(25));
    $old = $this->team->addMediaFromString(pdfBytes())->usingFileName('old.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $oldTemp = TemporaryUploads::newName('old.pdf');
    TemporaryUploads::disk()->put(TemporaryUploads::path($oldTemp), pdfBytes());
    touch(TemporaryUploads::disk()->path(TemporaryUploads::path($oldTemp)), now()->getTimestamp());

    $this->travelBack();
    $fresh = $this->team->addMediaFromString(pdfBytes())->usingFileName('fresh.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $freshTemp = TemporaryUploads::newName('fresh.pdf');
    TemporaryUploads::disk()->put(TemporaryUploads::path($freshTemp), pdfBytes());

    $this->artisan('app:purge-pending-uploads')
        ->expectsOutputToContain('Purged 1 pending upload(s) and 1 temp file(s).')
        ->assertSuccessful();

    expect(Media::query()->find($old->getKey()))->toBeNull()
        ->and(Media::query()->find($fresh->getKey()))->not->toBeNull();
    TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($oldTemp));
    TemporaryUploads::disk()->assertExists(TemporaryUploads::path($freshTemp));
});

it('is scheduled hourly', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('app:purge-pending-uploads')
        ->expectsOutputToContain('0 * * * *')
        ->assertSuccessful();
});
