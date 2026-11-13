<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Console\Commands\BackfillAutomatedReasonsCommand;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\AutomatedSenderOverride;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\AutomatedMailDetector;

mutates(BackfillAutomatedReasonsCommand::class, AutomatedMailDetector::class);

beforeEach(function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $this->workspace = $owner->currentWorkspace;
    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'user_id' => $owner->getKey(),
    ]));
    $this->backfill = fn (array $options = []): mixed => $this->artisan('email:backfill-automated-reasons', ['--force' => true, '--before' => now()->toIso8601String(), ...$options]);
    $this->history = function (string $from, ?EmailCategory $label = null, ?string $replyTo = null): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->account->user_id,
            'connected_account_id' => $this->account->getKey(),
            'created_at' => now()->subMonth(),
            'conversation_key' => hash('sha256', $from),
        ]);
        EmailParticipant::query()->create(['email_id' => $email->getKey(), 'email_address' => $from, 'role' => 'from']);
        if ($replyTo !== null) {
            EmailParticipant::query()->create(['email_id' => $email->getKey(), 'email_address' => $replyTo, 'role' => 'reply_to']);
        }
        if ($label instanceof EmailCategory) {
            EmailLabel::query()->create(['email_id' => $email->getKey(), 'label' => $label->value, 'source' => 'system', 'created_at' => now()]);
        }

        return $email;
    };
});

it('marks role senders and Marketing history, and leaves people alone', function (): void {
    $noreply = ($this->history)('noreply@stripe.example');
    $marketing = ($this->history)('marketing@brand.example', EmailCategory::Marketing);
    $person = ($this->history)('ana@acme.example', EmailCategory::Sales);

    ($this->backfill)()->expectsOutputToContain('2 email(s) marked')->assertSuccessful();

    expect($noreply->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($marketing->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($person->refresh()->automated_reason)->toBeNull()
        ->and(EmailConversation::query()->where('conversation_key', $noreply->conversation_key)->sole()->is_automated)->toBeTrue();
});

it('skips senders marked as a person and relayed form notifications', function (): void {
    AutomatedSenderOverride::query()->create(['workspace_id' => $this->workspace->getKey(), 'email_address' => 'noreply@founder.example']);
    $overridden = ($this->history)('noreply@founder.example');
    $relayed = ($this->history)('noreply@forms.example', EmailCategory::Marketing, 'polly@5forests.example');

    ($this->backfill)()->assertSuccessful();

    expect($overridden->refresh()->automated_reason)->toBeNull()
        ->and($relayed->refresh()->automated_reason)->toBeNull();
});

it('leaves mail stored after the cutoff alone and changes nothing on a rerun', function (): void {
    $old = ($this->history)('noreply@stripe.example');
    $fresh = ($this->history)('marketing@brand.example', EmailCategory::Marketing);
    $fresh->forceFill(['created_at' => now()->addMinute()])->saveQuietly();

    ($this->backfill)()->assertSuccessful();
    ($this->backfill)()->expectsOutputToContain('0 email(s) marked')->assertSuccessful();

    expect($old->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($fresh->refresh()->automated_reason)->toBeNull();
});

it('reports without writing', function (): void {
    $email = ($this->history)('noreply@stripe.example');

    $this->artisan('email:backfill-automated-reasons', ['--before' => now()->toIso8601String()])
        ->expectsOutputToContain('1 email(s) would be marked')
        ->assertSuccessful();

    expect($email->refresh()->automated_reason)->toBeNull();
});

it('refuses to write without a cutoff', function (): void {
    ($this->history)('noreply@stripe.example');

    $this->artisan('email:backfill-automated-reasons', ['--force' => true])->assertFailed();

    expect(Email::query()->whereNotNull('automated_reason')->exists())->toBeFalse();
});

it('marks without touching updated_at or a reason sync already wrote', function (): void {
    $email = ($this->history)('noreply@stripe.example');
    $synced = ($this->history)('notifications@github.example');
    Email::query()->whereKey($synced->getKey())->toBase()->update(['automated_reason' => AutomatedReason::List->value]);
    $updatedAt = $email->refresh()->updated_at;
    $this->travel(1)->hours();

    ($this->backfill)()->expectsOutputToContain('1 email(s) marked')->assertSuccessful();

    expect($email->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($synced->refresh()->automated_reason)->toBe(AutomatedReason::List);
});

it('leaves other workspaces alone when it runs for one', function (): void {
    $other = Email::factory()->create(['created_at' => now()->subMonth()]);
    EmailParticipant::query()->create(['email_id' => $other->getKey(), 'email_address' => 'noreply@stripe.example', 'role' => 'from']);
    $mine = ($this->history)('noreply@stripe.example');

    ($this->backfill)(['--workspace' => $this->workspace->getKey()])->assertSuccessful();

    expect($mine->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($other->refresh()->automated_reason)->toBeNull();
});

it('queues the rest after the last email it read when a run reaches its chunk', function (): void {
    Queue::fake();
    $first = ($this->history)('noreply@stripe.example');
    $second = ($this->history)('noreply@slack.example');
    $before = now()->toIso8601String();

    ($this->backfill)(['--chunk' => 1, '--before' => $before, '--workspace' => $this->workspace->getKey()])
        ->expectsOutputToContain('The rest is queued')
        ->assertSuccessful();

    expect($first->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($second->refresh()->automated_reason)->toBeNull()
        ->and(EmailConversation::query()->where('conversation_key', $first->conversation_key)->sole()->is_automated)->toBeTrue();
    Queue::assertPushedOn('imports', QueuedCommand::class, fn (QueuedCommand $job): bool => (fn (): array => $this->data)->call($job) === [
        'email:backfill-automated-reasons',
        ['--force' => true, '--before' => $before, '--after' => $first->getKey(), '--workspace' => $this->workspace->getKey(), '--chunk' => 1],
    ]);
});

it('resumes after the last email the queued run names', function (): void {
    Queue::fake();
    $person = ($this->history)('ana@acme.example');
    $noreply = ($this->history)('noreply@stripe.example');

    ($this->backfill)(['--chunk' => 1])->assertSuccessful();
    $queued = [];
    Queue::assertPushed(QueuedCommand::class, function (QueuedCommand $job) use (&$queued): bool {
        $queued = (fn (): array => $this->data)->call($job);

        return true;
    });

    expect($queued[1]['--after'])->toBe($person->getKey());

    $this->artisan(...$queued)->assertSuccessful();

    expect($noreply->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($person->refresh()->automated_reason)->toBeNull();
    Queue::assertPushed(QueuedCommand::class, 2);
    Queue::assertPushedOn('imports', QueuedCommand::class, fn (QueuedCommand $job): bool => (fn (): array => $this->data)->call($job) === [
        'email:backfill-conversations',
        ['--force' => true],
    ]);
});

it('writes nothing on a report-only run that spans pages', function (): void {
    ($this->history)('noreply@stripe.example');
    Email::factory()->count(500)->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->account->user_id,
        'connected_account_id' => $this->account->getKey(),
        'created_at' => now()->subMonth(),
    ]);

    $this->artisan('email:backfill-automated-reasons', ['--before' => now()->toIso8601String()])
        ->expectsOutputToContain('1 email(s) would be marked')
        ->assertSuccessful();

    expect(EmailConversation::query()->exists())->toBeFalse()
        ->and(Email::query()->withoutGlobalScopes()->whereNotNull('automated_reason')->exists())->toBeFalse();
});

it('refreshes the summaries it marks without signalling a conversation change', function (): void {
    Event::fake([ConversationChanged::class]);
    $noreply = ($this->history)('noreply@stripe.example');

    ($this->backfill)()->expectsOutputToContain('1 email(s) marked')->assertSuccessful();

    expect(EmailConversation::query()->where('conversation_key', $noreply->conversation_key)->sole()->is_automated)->toBeTrue();
    Event::assertNotDispatched(ConversationChanged::class);
});
