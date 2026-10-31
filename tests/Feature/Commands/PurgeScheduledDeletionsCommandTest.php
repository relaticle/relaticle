<?php

declare(strict_types=1);

use App\Actions\Jetstream\DeleteWorkspace;
use App\Enums\MediaCollection;
use App\Jobs\PurgeUser;
use App\Jobs\PurgeWorkspace;
use App\Models\Note;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\Meeting;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(DeleteWorkspace::class, PurgeUser::class, PurgeWorkspace::class);

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
        'steps' => '[]',
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

test('purging a user removes mail and meetings synced from a mailbox they already disconnected', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $member = User::factory()->scheduledForDeletion(-1)->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $account = ConnectedAccount::factory()->for($workspace)->for($member)->create();
    $email = Email::factory()->for($workspace)->for($member)->for($account)->create();
    EmailBody::factory()->for($email)->create();
    $meeting = Meeting::factory()->for($workspace)->for($account)->create();

    $account->delete();

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(DB::table('connected_accounts')->where('id', $account->id)->exists())->toBeFalse()
        ->and(DB::table('emails')->where('id', $email->id)->exists())->toBeFalse()
        ->and(DB::table('email_bodies')->where('email_id', $email->id)->exists())->toBeFalse()
        ->and(DB::table('meetings')->where('id', $meeting->id)->exists())->toBeFalse()
        ->and(Workspace::query()->find($workspace->id))->not->toBeNull();
});

test('expired users and workspaces are queued for purge on the imports lane', function () {
    $expiredUser = User::factory()->scheduledForDeletion(-1)->create();
    $futureUser = User::factory()->scheduledForDeletion(15)->create();
    $expiredWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $expiredWorkspace->update(['scheduled_deletion_at' => now()->subDay()]);
    Queue::fake([PurgeUser::class, PurgeWorkspace::class]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Queue::assertPushedOn('imports', PurgeUser::class, fn (PurgeUser $job): bool => $job->user->is($expiredUser));
    Queue::assertNotPushed(PurgeUser::class, fn (PurgeUser $job): bool => $job->user->is($futureUser));
    Queue::assertPushedOn('imports', PurgeWorkspace::class, fn (PurgeWorkspace $job): bool => $job->workspace->is($expiredWorkspace));
    expect($expiredUser->fresh())->not->toBeNull();
});

test('a workspace whose owner is due is purged with its owner, not queued twice', function () {
    $owner = User::factory()->withPersonalWorkspace()->scheduledForDeletion(-1)->create();
    $personalWorkspace = $owner->personalWorkspace();
    $personalWorkspace->update(['scheduled_deletion_at' => $owner->scheduled_deletion_at]);
    Queue::fake([PurgeUser::class, PurgeWorkspace::class]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Queue::assertPushed(PurgeUser::class, fn (PurgeUser $job): bool => $job->user->is($owner));
    Queue::assertNotPushed(PurgeWorkspace::class, fn (PurgeWorkspace $job): bool => $job->workspace->is($personalWorkspace));
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

it('removes the payment failure notice of a purged workspace and keeps the owner\'s other notices', function (): void {
    withHostedBilling();

    $owner = User::factory()->withWorkspace()->create();
    $doomed = $owner->currentWorkspace;
    $kept = Workspace::factory()->for($owner, 'owner')->create();
    $owner->notify(new PaymentFailedNotification($doomed));
    $owner->notify(new PaymentFailedNotification($kept));
    $doomed->update(['scheduled_deletion_at' => now()->subDay()]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect($owner->notifications()->pluck('data')->pluck('workspace_id')->all())->toBe([$kept->getKey()]);
});

it('removes a purged workspace\'s custom fields, options and values, and leaves other workspaces alone', function (): void {
    $doomed = User::factory()->withWorkspace()->create()->currentWorkspace;
    $kept = User::factory()->withWorkspace()->create()->currentWorkspace;

    $rows = fn (Workspace $workspace): array => [
        DB::table('custom_fields')->where('tenant_id', $workspace->getKey())->count(),
        DB::table('custom_field_options')->where('tenant_id', $workspace->getKey())->count(),
        DB::table('custom_field_values')->where('tenant_id', $workspace->getKey())->count(),
    ];

    foreach ([$doomed, $kept] as $workspace) {
        $field = DB::table('custom_fields')->where('tenant_id', $workspace->getKey())->where('code', 'status')->first();

        DB::table('custom_field_values')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $workspace->getKey(),
            'entity_type' => 'task',
            'entity_id' => (string) Str::ulid(),
            'custom_field_id' => $field->id,
            'string_value' => 'orphan',
        ]);
    }

    $keptBefore = $rows($kept);

    expect($rows($doomed)[0])->toBeGreaterThan(0)
        ->and($rows($doomed)[1])->toBeGreaterThan(0);

    resolve(DeleteWorkspace::class)->delete($doomed);

    expect($rows($doomed))->toBe([0, 0, 0])
        ->and($rows($kept))->toBe($keptBefore);
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

it('keeps the members and custom fields of a workspace whose purge fails', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->subDay()]);
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);
    $customFields = DB::table('custom_fields')->where('tenant_id', $workspace->getKey())->count();
    Workspace::deleting(function (Workspace $deleting) use ($workspace): void {
        if ($deleting->is($workspace)) {
            throw new RuntimeException('Workspace deletion failed');
        }
    });

    expect(fn (): int => Artisan::call('app:purge-scheduled-deletions'))
        ->toThrow(RuntimeException::class, 'Workspace deletion failed');

    expect($workspace->users()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and(DB::table('custom_fields')->where('tenant_id', $workspace->getKey())->count())->toBe($customFields)
        ->and($customFields)->toBeGreaterThan(0);
});

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
