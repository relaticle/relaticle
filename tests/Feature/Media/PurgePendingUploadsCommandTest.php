<?php

declare(strict_types=1);

use App\Actions\Upload\PurgeExpiredUploads;
use App\Console\Commands\PurgePendingUploadsCommand;
use App\Enums\MediaCollection;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(PurgeExpiredUploads::class, PurgePendingUploadsCommand::class);

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
    $oldTemp = TemporaryUploads::newName('old.pdf', (string) $this->team->getKey());
    TemporaryUploads::disk()->put(TemporaryUploads::path($oldTemp), pdfBytes());
    touch(TemporaryUploads::disk()->path(TemporaryUploads::path($oldTemp)), now()->getTimestamp());

    $this->travelBack();
    $fresh = $this->team->addMediaFromString(pdfBytes())->usingFileName('fresh.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $freshTemp = TemporaryUploads::newName('fresh.pdf', (string) $this->team->getKey());
    TemporaryUploads::disk()->put(TemporaryUploads::path($freshTemp), pdfBytes());

    $this->artisan('app:purge-pending-uploads')
        ->expectsOutputToContain('Purged 1 pending upload(s) and 1 temp file(s).')
        ->assertSuccessful();

    expect(Media::query()->find($old->getKey()))->toBeNull()
        ->and(Media::query()->find($fresh->getKey()))->not->toBeNull();
    TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($oldTemp));
    TemporaryUploads::disk()->assertExists(TemporaryUploads::path($freshTemp));
});

it('is scheduled hourly without overlap on a single server', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $schedule = resolve(Schedule::class);
    $event = collect($schedule->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'app:purge-pending-uploads'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('rejects a non-positive retention window without deleting uploads', function (string $hours): void {
    $pending = $this->team->addMediaFromString(pdfBytes())->usingFileName('pending.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $this->artisan('app:purge-pending-uploads', ['--hours' => $hours])
        ->expectsOutputToContain('The retention window must be at least one hour.')
        ->assertFailed();

    expect(Media::query()->find($pending->getKey()))->not->toBeNull();
})->with(['zero' => '0', 'negative' => '-1', 'not numeric' => 'tomorrow']);
