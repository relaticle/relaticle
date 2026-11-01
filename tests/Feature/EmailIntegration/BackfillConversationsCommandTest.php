<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Console\Commands\BackfillConversationsCommand;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;

mutates(BackfillConversationsCommand::class);

beforeEach(function (): void {
    $this->workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $this->mailbox = fn (): ConnectedAccount => ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => User::factory()->create()->getKey(),
    ]));
    $this->email = fn (ConnectedAccount $mailbox, array $attributes = []): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->getKey(),
        'conversation_key' => null,
        ...$attributes,
    ]);
});

it('reports unkeyed mail without writing', function (): void {
    ($this->email)(($this->mailbox)());

    $this->artisan('email:backfill-conversations')->expectsOutputToContain('1 email(s) without a conversation key')->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(1)
        ->and(EmailConversation::query()->count())->toBe(0);
});

it('keys history across mailboxes and builds one summary per conversation', function (): void {
    [$mine, $theirs] = [($this->mailbox)(), ($this->mailbox)()];
    ($this->email)($mine, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'ta', 'sent_at' => now()->subDay()]);
    ($this->email)($theirs, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'tb', 'sent_at' => now()->subDay()]);
    ($this->email)($theirs, ['rfc_message_id' => '<r@acme.example>', 'thread_id' => 'tc', 'in_reply_to' => '<p@acme.example>']);
    ($this->email)($mine, ['rfc_message_id' => '<x@acme.example>', 'thread_id' => 'td']);

    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(0)
        ->and(Email::query()->distinct()->count('conversation_key'))->toBe(2)
        ->and(EmailConversation::query()->orderByDesc('message_count')->pluck('message_count')->all())->toBe([2, 1]);
});

it('joins an older reply whose stored in-reply-to is a raw header to its parent', function (): void {
    $mailbox = ($this->mailbox)();
    $reply = ($this->email)($mailbox, [
        'rfc_message_id' => '<r@acme.example>',
        'thread_id' => 'tr',
        'in_reply_to' => ' <p@acme.example> (comment)',
        'references' => '<z@acme.example>',
    ]);
    $parent = ($this->email)($mailbox, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'tp', 'sent_at' => now()->subDay()]);

    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    expect($reply->fresh()->conversation_key)->toBe($parent->fresh()->conversation_key)
        ->and($reply->fresh()->in_reply_to)->toBe('<p@acme.example>')
        ->and(EmailConversation::query()->pluck('message_count')->all())->toBe([2]);
});

it('changes nothing when it runs a second time', function (): void {
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox, ['rfc_message_id' => '<p@acme.example>']);
    ($this->email)($mailbox, ['rfc_message_id' => '<r@acme.example>', 'in_reply_to' => '<p@acme.example>', 'thread_id' => 'other']);
    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();
    $keys = Email::query()->orderBy('id')->pluck('conversation_key', 'id')->all();
    $summaries = EmailConversation::query()->get(['id', 'message_count', 'updated_at'])->toArray();

    $this->travel(1)->hours();
    $this->artisan('email:backfill-conversations', ['--force' => true])
        ->expectsOutputToContain('0 email(s) keyed, 0 summary(ies) rebuilt')
        ->assertSuccessful();

    expect(Email::query()->orderBy('id')->pluck('conversation_key', 'id')->all())->toBe($keys)
        ->and(EmailConversation::query()->get(['id', 'message_count', 'updated_at'])->toArray())->toBe($summaries);
});

it('drops the summary of a disconnected mailbox', function (): void {
    [$gone, $kept] = [($this->mailbox)(), ($this->mailbox)()];
    ($this->email)($gone, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'ta', 'sent_at' => now()->subDay()]);
    ($this->email)($kept, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'tb', 'sent_at' => now()->subDay()]);
    ($this->email)($gone, ['rfc_message_id' => '<r@acme.example>', 'thread_id' => 'ta', 'in_reply_to' => '<p@acme.example>']);
    ($this->email)($gone, ['rfc_message_id' => '<x@acme.example>', 'thread_id' => 'tx']);
    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();
    expect(EmailConversation::query()->orderByDesc('message_count')->pluck('message_count')->all())->toBe([2, 1]);

    $gone->delete();
    $this->artisan('email:backfill-conversations', ['--force' => true, '--workspace' => $this->workspace->getKey()])
        ->expectsOutputToContain('0 email(s) keyed, 2 summary(ies) rebuilt')
        ->assertSuccessful();

    expect(EmailConversation::query()->get(['message_count', 'last_message_at'])->map(fn (EmailConversation $summary): array => [$summary->message_count, $summary->last_message_at->toDateString()])->all())
        ->toBe([[1, now()->subDay()->toDateString()]]);
});

it('queues the rest on the imports lane when a run reaches its chunk', function (): void {
    Queue::fake();
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox);
    ($this->email)($mailbox);

    $this->artisan('email:backfill-conversations', ['--force' => true, '--chunk' => 1])->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(1);
    Queue::assertPushedOn('imports', QueuedCommand::class);
});

it('queues the summaries a run had no room left to rebuild', function (): void {
    Queue::fake();
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox);
    ($this->email)($mailbox);

    $this->artisan('email:backfill-conversations', ['--force' => true, '--chunk' => 2])
        ->expectsOutputToContain('2 email(s) keyed, 0 summary(ies) rebuilt. The rest is queued.')
        ->assertSuccessful();

    expect(EmailConversation::query()->count())->toBe(0);
    Queue::assertPushedOn('imports', QueuedCommand::class);
});

it('leaves drafts without a key', function (): void {
    ($this->email)(($this->mailbox)(), ['status' => EmailStatus::DRAFT, 'sent_at' => null]);

    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    expect(Email::query()->whereNotNull('conversation_key')->count())->toBe(0);
});
