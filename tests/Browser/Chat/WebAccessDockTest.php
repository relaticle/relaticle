<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\GrantWebAccess;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatBrowser;

mutates(ProposalCard::class);

/** @return array{page: mixed, request: PendingAction} */
function openWebAccessRequest(): array
{
    withHostedBilling();
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'web research', $conversationId);

    $request = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'turn_id' => (string) Str::ulid(),
        'action_class' => GrantWebAccess::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => ProposalEntity::WebAccess,
        'action_data' => ['query' => 'Filaforms'],
        'display_data' => ['title' => 'Search web', 'summary' => 'Filaforms', 'reason' => 'Filaforms is not in your workspace yet.'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertVisible('[data-web-access-dock]');

    return ['page' => $page, 'request' => $request];
}

it('asks for web access with the query, the reason, the price and three choices', function (): void {
    ['page' => $page] = openWebAccessRequest();

    $page->assertSeeIn('[data-web-access-dock]', 'Search web:')
        ->assertSeeIn('[data-web-access-query]', 'Filaforms')
        ->assertSeeIn('[data-web-access-reason]', 'Filaforms is not in your workspace yet.')
        ->assertSee('Rela wants to search the web for this. Each search uses 2 credits.')
        ->assertVisible('[wire\:click^="rejectStep"]')
        ->assertVisible('[wire\:click^="approveAlways"]')
        ->assertVisible('[wire\:click^="approveStep"]')
        ->assertSee('Always allow')
        ->assertSee('Deny');
});

it('allows web access with the keyboard shortcut', function (): void {
    ['page' => $page, 'request' => $request] = openWebAccessRequest();

    $page->keys('[data-web-access-dock]', 'Control+Enter')
        ->assertMissing('[data-web-access-dock]');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('declines web access with Deny', function (): void {
    ['page' => $page, 'request' => $request] = openWebAccessRequest();

    $page->click('[wire\:click^="rejectStep"]')
        ->assertMissing('[data-web-access-dock]');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Rejected);
});
