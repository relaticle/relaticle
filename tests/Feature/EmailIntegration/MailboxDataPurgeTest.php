<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Relaticle\EmailIntegration\Actions\PurgeStoredMailboxData;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailThread;

mutates(PurgeStoredMailboxData::class);

beforeEach(function (): void {
    Storage::fake(EmailAttachment::DISK);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->member = User::factory()->create();
    $this->workspace->users()->attach($this->member, ['role' => 'member']);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->member->id,
    ]));

    $this->thread = EmailThread::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->member->id,
        'connected_account_id' => $this->account->getKey(),
        'thread_id' => $this->thread->thread_id,
    ]);

    $this->path = "email-attachments/{$email->getKey()}/logo.png";
    Storage::disk(EmailAttachment::DISK)->put($this->path, 'bytes');

    EmailAttachment::factory()->create([
        'email_id' => $email->getKey(),
        'storage_path' => $this->path,
        'is_inline' => true,
    ]);

    AiSummary::query()->create([
        'workspace_id' => $this->workspace->id,
        'summarizable_type' => $this->thread->getMorphClass(),
        'summarizable_id' => $this->thread->getKey(),
        'summary' => 'A summary of the thread.',
        'input_hash' => 'hash',
        'model_used' => 'model',
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
    ]);
});

it('removes a deleted member\'s stored attachment files and thread summaries from a surviving workspace', function (): void {
    resolve(DeletesUsers::class)->delete($this->member);

    expect(Email::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $this->member->id)->count())->toBe(0)
        ->and(AiSummary::query()->where('summarizable_id', $this->thread->getKey())->count())->toBe(0)
        ->and(Storage::disk(EmailAttachment::DISK)->exists($this->path))->toBeFalse();
});

it('removes stored attachment files and composer images when the workspace is deleted', function (): void {
    $composerImage = EmailAttachment::composeImagesDirectory((string) $this->workspace->id).'/pasted.png';
    Storage::disk(EmailAttachment::DISK)->put($composerImage, 'bytes');

    resolve(DeletesTeams::class)->delete($this->workspace);

    expect(Storage::disk(EmailAttachment::DISK)->exists($this->path))->toBeFalse()
        ->and(Storage::disk(EmailAttachment::DISK)->exists($composerImage))->toBeFalse();
});

it('keeps a teammate\'s stored attachment files and thread summaries when another member is deleted', function (): void {
    $departing = User::factory()->create();
    $this->workspace->users()->attach($departing, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $departing->id,
    ]));

    $email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $departing->id,
        'connected_account_id' => $account->getKey(),
    ]);

    $departingPath = "email-inline-attachments/{$email->getKey()}/file";
    Storage::disk(EmailAttachment::DISK)->put($departingPath, 'bytes');
    EmailAttachment::factory()->create(['email_id' => $email->getKey(), 'storage_path' => $departingPath]);

    resolve(DeletesUsers::class)->delete($departing);

    expect(Storage::disk(EmailAttachment::DISK)->exists($departingPath))->toBeFalse()
        ->and(Storage::disk(EmailAttachment::DISK)->exists($this->path))->toBeTrue()
        ->and(AiSummary::query()->where('summarizable_id', $this->thread->getKey())->count())->toBe(1);
});
