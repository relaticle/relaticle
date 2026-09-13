<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(PendingActionService::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);
    $this->travelTo(now()->startOfSecond());

    $this->user = User::factory()->withPersonalWorkspace(function (Workspace $workspace): void {
        $workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Sales])->save();
    })->create();
    $this->workspace = $this->user->currentWorkspace;
});

function proposeIn(User $user, string $conversationId): PendingAction
{
    return resolve(PendingActionService::class)->createProposal(
        user: $user,
        conversationId: $conversationId,
        actionClass: CreatePeople::class,
        operation: PendingActionOperation::Create,
        entityType: 'people',
        actionData: ['name' => 'Jane Doe'],
        displayData: ['title' => 'Jane Doe', 'summary' => 'Create person "Jane Doe"', 'fields' => []],
    );
}

it('gives a setup conversation proposal seven days', function (): void {
    $proposal = proposeIn($this->user, $this->workspace->setupConversation->id);

    expect($proposal->expires_at->equalTo(now()->addMinutes(10080)))->toBeTrue();
});

it('keeps the ordinary expiry elsewhere', function (): void {
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

    $proposal = proposeIn($this->user, $conversationId);

    expect($proposal->expires_at->equalTo(now()->addMinutes((int) config('chat.pending_action_expiry_minutes'))))->toBeTrue();
});

it('keeps the ordinary expiry in the setup conversation when the flag is off', function (): void {
    $conversationId = $this->workspace->setupConversation->id;
    Feature::define(SetupConversation::class, false);
    Feature::flushCache();

    $proposal = proposeIn($this->user, $conversationId);

    expect($proposal->expires_at->equalTo(now()->addMinutes((int) config('chat.pending_action_expiry_minutes'))))->toBeTrue();
});
