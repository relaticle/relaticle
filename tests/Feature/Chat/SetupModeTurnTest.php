<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(ProcessChatMessage::class, CrmAssistant::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);

    $this->user = User::factory()->withPersonalWorkspace(function (Workspace $workspace): void {
        $workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Recruiting])->save();
    })->create();
    $this->workspace = $this->user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'workspace_id' => $this->workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

function runTurn(User $user, Workspace $workspace, string $conversationId, bool $continuation = false): void
{
    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Jane Doe, Acme, jane@acme.test',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        isContinuation: $continuation,
    );

    $job->handle(resolve(CreditService::class));
}

it('runs the setup conversation in setup mode', function (): void {
    CrmAssistant::fake(['Review the proposal below.']);

    runTurn($this->user, $this->workspace, $this->workspace->setupConversation->id);

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->agent->setupMode === true);
});

it('keeps setup mode on a resumed turn in the setup conversation', function (): void {
    CrmAssistant::fake(['Created Jane Doe.']);

    runTurn($this->user, $this->workspace, $this->workspace->setupConversation->id, continuation: true);

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->agent->setupMode === true);
});

it('runs an ordinary conversation without setup mode', function (): void {
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'ordinary',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['Sure.']);

    runTurn($this->user, $this->workspace, $conversationId);

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->agent->setupMode === false);
});

it('drops setup mode when the flag is off', function (): void {
    $conversationId = $this->workspace->setupConversation->id;
    Feature::define(SetupConversation::class, false);

    CrmAssistant::fake(['Sure.']);

    runTurn($this->user, $this->workspace, $conversationId);

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->agent->setupMode === false);
});
