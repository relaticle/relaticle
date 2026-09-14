<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Enums\CreationSource;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Filament\Pages\Dashboard;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\TurnPresence;
use Tests\Helpers\ChatBrowser;

mutates(Dashboard::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function setupWorkspace(): array
{
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->getKey(),
        'personal_workspace' => true,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
        'trial_ends_at' => now()->addDays(14),
    ]);
    $user->ownedWorkspaces()->save($workspace);
    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    return [$user, $workspace->fresh()];
}

it('opens on the setup conversation and sends the first message into it', function (): void {
    [$user, $workspace] = setupWorkspace();
    $conversationId = $workspace->setupConversation->id;

    Queue::fake();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertSee('Your candidate pipeline is ready: Sourced through Hired.')
        ->assertSee('Not now')
        ->assertDontSee('Good morning');

    $page->script(<<<'JS'
        (() => {
            const wrapper = document.querySelector('[data-chat-context="dashboard"][x-data*="chatEditor"]');
            Alpine.$data(wrapper).setText('Jane Doe, Acme, jane@acme.test');
            document.querySelector('[data-chat-context="dashboard"]').closest('form').requestSubmit();
        })();
    JS);

    $page->waitForText('Jane Doe, Acme, jane@acme.test')
        ->assertPathContains("/chats/{$conversationId}")
        ->assertSee('Your candidate pipeline is ready');

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->conversationId === $conversationId);

    expect(TurnPresence::current($conversationId))->not->toBeNull();
});

it('approves a seeded proposal in the setup thread and completes the first-record step', function (): void {
    [$user, $workspace] = setupWorkspace();
    $conversationId = $workspace->setupConversation->id;
    $names = ['Ada Lovelace', 'Grace Hopper', 'Linus Torvalds', 'Margaret Hamilton', 'Ken Thompson'];

    PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['_batch' => true, 'records' => array_map(fn (string $name): array => ['name' => $name], $names)],
        'display_data' => [
            'title' => 'Create people',
            'summary' => 'Create 5 people',
            'items' => array_map(fn (string $name): array => ['title' => $name, 'summary' => "Create person \"{$name}\"", 'fields' => [['label' => 'Name', 'value' => $name]]], $names),
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addDays(7),
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId);

    foreach ($names as $name) {
        $page->assertSee($name)
            ->script(<<<'JS'
                document.querySelector('[wire\\:click="createCurrent"]')?.click();
            JS);
    }

    $page->waitForText('5 created');

    expect(People::query()->where('workspace_id', $workspace->getKey())->where('creation_source', CreationSource::CHAT)->count())->toBe(5);

    // The browser test server keeps one app container alive across requests,
    // so the scoped fact cache from the earlier dashboard load survives here.
    app()->forgetInstance(WorkspaceActivationFacts::class);

    $page->navigate("/app/{$workspace->slug}")
        ->assertSourceHas('data-step="first_record" data-complete="true"');
});
