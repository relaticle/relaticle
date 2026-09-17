<?php

declare(strict_types=1);

use App\Actions\Jetstream\DeleteWorkspace;
use App\Enums\MediaCollection;
use App\Models\Note;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(DeleteWorkspace::class);

test('expired users are permanently deleted', function () {
    $user = User::factory()->withPersonalWorkspace()->scheduledForDeletion(-1)->create();
    $userId = $user->id;

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(User::query()->find($userId))->toBeNull();
});

test('purging a user anonymises their chat participation in workspaces that survive', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $member = User::factory()->scheduledForDeletion(-1)->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $member->getMorphClass(),
        'participant_id' => (string) $member->id,
        'workspace_id' => (string) $workspace->id,
        'title' => 'Member conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $member->getMorphClass(),
        'participant_id' => (string) $member->id,
        'agent' => 'test-agent',
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(User::query()->find($member->id))->toBeNull()
        ->and(DB::table('agent_conversations')->where('participant_id', (string) $member->id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('participant_id', (string) $member->id)->exists())->toBeFalse();

    $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_id)->toBeNull()
        ->and($conversation->participant_type)->toBeNull();
});

test('non-expired users are not deleted', function () {
    $user = User::factory()->withPersonalWorkspace()->scheduledForDeletion(15)->create();

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect($user->refresh())->not->toBeNull();
});

test('expired workspaces are permanently deleted', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->subDay()]);
    $workspaceId = $workspace->id;

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(Workspace::query()->find($workspaceId))->toBeNull();
});

test('day 25 reminder is sent for users', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->scheduledForDeletion(5)->create();

    $this->travelTo(now());

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Notification::assertSentTo($user, UserDeletionReminderNotification::class);
});

test('day 25 reminder is sent to workspace owner only', function () {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->addDays(5)]);
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Notification::assertSentTo($owner, WorkspaceDeletionReminderNotification::class);
    Notification::assertNotSentTo($member, WorkspaceDeletionReminderNotification::class);
});

test('ownerless workspaces are skipped without aborting the deletion reminders', function () {
    Notification::fake();

    $ownerlessWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $ownerlessWorkspace->update(['scheduled_deletion_at' => now()->addDays(5)]);
    User::query()->whereKey($ownerlessWorkspace->user_id)->delete();

    $owner = User::factory()->withWorkspace()->create();
    $owner->currentWorkspace->update(['scheduled_deletion_at' => now()->addDays(5)]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Notification::assertSentTo($owner, WorkspaceDeletionReminderNotification::class);
    Notification::assertSentTimes(WorkspaceDeletionReminderNotification::class, 1);
});

it('removes every workspace upload during permanent deletion and preserves other workspaces', function (bool $deleteOwner): void {
    Storage::fake('local');
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $note = Note::factory()->create(['workspace_id' => $workspace->getKey()]);
    $attachment = $note->addMediaFromString(pdfBytes())->usingFileName('contract.pdf')
        ->withAttributes(['workspace_id' => $workspace->getKey()])
        ->toMediaCollection(MediaCollection::Attachments->value);
    $pending = $workspace->addMediaFromString(pdfBytes())->usingFileName('pending.pdf')
        ->withAttributes(['workspace_id' => $workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $unrelated = $otherWorkspace->addMediaFromString(pdfBytes())->usingFileName('other.pdf')
        ->withAttributes(['workspace_id' => $otherWorkspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $attachmentPath = $attachment->getPathRelativeToRoot();
    $pendingPath = $pending->getPathRelativeToRoot();
    $note->delete();
    ($deleteOwner ? $owner : $workspace)->update(['scheduled_deletion_at' => now()->subDay()]);

    $this->artisan('app:purge-scheduled-deletions')->assertSuccessful();

    expect(Workspace::query()->find($workspace->getKey()))->toBeNull()
        ->and(Media::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse()
        ->and($unrelated->fresh())->not->toBeNull();
    Storage::disk('local')->assertMissing([$attachmentPath, $pendingPath]);
    Storage::disk('local')->assertExists($unrelated->getPathRelativeToRoot());
})->with(['workspace deletion' => false, 'owner deletion' => true]);

it('keeps attachment bytes when permanent workspace deletion rolls back', function (): void {
    Storage::fake('local');
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->subDay()]);
    $note = Note::factory()->create(['workspace_id' => $workspace->getKey()]);
    $attachment = $note->addMediaFromString(pdfBytes())->usingFileName('contract.pdf')
        ->withAttributes(['workspace_id' => $workspace->getKey()])
        ->toMediaCollection(MediaCollection::Attachments->value);
    Workspace::deleting(function (Workspace $deleting) use ($workspace): void {
        if ($deleting->is($workspace)) {
            throw new RuntimeException('Workspace deletion failed');
        }
    });

    expect(fn (): int => Artisan::call('app:purge-scheduled-deletions'))
        ->toThrow(RuntimeException::class, 'Workspace deletion failed');

    expect($workspace->fresh())->not->toBeNull()
        ->and($attachment->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->getPathRelativeToRoot());
});
