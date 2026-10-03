<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\TurnPresence;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

function seedConversation(User $user, string $conversationId): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('broadcasts a stream.failed event when the job fails', function (): void {
    Event::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    seedConversation($user, 'conv-123');

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: 'conv-123',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
    );

    $job->failed(new RuntimeException('boom'));

    Event::assertDispatched(ChatStreamFailed::class, function (ChatStreamFailed $event) {
        return $event->conversationId === 'conv-123';
    });
});

it('refunds the reservation when the job fails without ever streaming', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    seedConversation($user, 'conv-123');

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 99,
        'credits_used' => 1,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: 'conv-123',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
    );

    $job->failed(new RuntimeException('boom'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    // handle() never ran, so the reservation goes back rather than being charged.
    expect($balance->credits_used)->toBe(0)
        ->and($balance->credits_remaining)->toBe(100);
});

it('binds auth context so tool classes can resolve the current user', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    Auth::guard('web')->setUser($user);
    expect(Auth::guard('web')->user()?->getKey())->toBe($user->getKey());
});

it('refunds the reservation and stops when hosted access expires in the queue', function (): void {
    Feature::define(BillingFeature::class, true);
    Event::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    seedConversation($user, 'conv-paused');

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $credits = resolve(CreditService::class);
    expect($credits->reserveCredit(
        $workspace,
        reservationKey: 'reserve-turn-paused',
        conversationId: 'conv-paused',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: 'conv-paused',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: 'turn-paused',
    );

    TurnPresence::begin('conv-paused', turnId: 'turn-paused', message: 'hello');

    $job->handle($credits);

    expect(TurnPresence::current('conv-paused'))->toBeNull();

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->conversationId === 'conv-paused'
        && $event->message === __('billing.access.paused_chat'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});

/** @param array{provider: string, model: string, id: string, source: string} $resolved */
function instructionsForTurn(User $user, array $resolved): string
{
    seedConversation($user, 'conv-instructions');

    CrmAssistant::fake(['ok']);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'hello',
        conversationId: 'conv-instructions',
        resolved: $resolved,
    )->handle(resolve(CreditService::class));

    $instructions = '';

    CrmAssistant::assertPrompted(function ($prompt) use (&$instructions): bool {
        $instructions = (string) $prompt->agent->instructions();

        return true;
    });

    return $instructions;
}

it('tells the assistant which model answers the turn and who chose it', function (string $id, string $provider, string $source, string $expected): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $instructions = instructionsForTurn($user, ['provider' => $provider, 'model' => $id, 'id' => $id, 'source' => $source]);

    expect($instructions)->toContain("## Model\nThis reply is generated by {$expected}");
})->with([
    'auto' => ['claude-sonnet-5', 'anthropic', 'auto', 'Sonnet 5 (Auto selected it for this turn)'],
    'explicit pick' => ['gpt-5.5', 'openai', 'explicit', 'GPT 5.5 (the user picked it)'],
]);

it('leaves the model line out when the catalog does not know the model', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $instructions = instructionsForTurn($user, ['provider' => 'anthropic', 'model' => 'retired-model', 'id' => 'retired-model', 'source' => 'explicit']);

    expect($instructions)->not->toContain('## Model');
});

it('tells the assistant it cannot send email when the email integration is off', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    $user = User::factory()->withPersonalWorkspace()->create();

    $instructions = instructionsForTurn($user, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto']);

    expect($instructions)->toContain('Email: this workspace cannot send email')
        ->not->toContain('Send Email bulk action');
});

it('points a user without a sendable mailbox to the email accounts page', function (): void {
    config()->set('relaticle.features.email_integration', true);
    Feature::flushCache();
    $user = User::factory()->withPersonalWorkspace()->create();

    $instructions = instructionsForTurn($user, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto']);

    expect($instructions)->toContain('give the "email_accounts" destination link');
});

it('sends a user with a sendable mailbox to the Send Email bulk action', function (): void {
    config()->set('relaticle.features.email_integration', true);
    Feature::flushCache();
    $user = User::factory()->withPersonalWorkspace()->create();
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
    ]));

    $instructions = instructionsForTurn($user, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto']);

    expect($instructions)->toContain('use the Send Email bulk action');
});
