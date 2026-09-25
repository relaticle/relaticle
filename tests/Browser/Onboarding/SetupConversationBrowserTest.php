<?php

declare(strict_types=1);

use App\Actions\Onboarding\StartSetupGreeting;
use App\Actions\People\CreatePeople;
use App\Enums\CreationSource;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\TurnPresence;
use Tests\Helpers\ChatBrowser;

mutates(StartSetupGreeting::class);

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

it('opens the setup conversation with the assistant already speaking', function (): void {
    [$user, $workspace] = setupWorkspace();
    $conversationId = $workspace->setupConversation->id;

    Queue::fake();

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertPathContains("/chats/{$conversationId}")
        ->assertSee('Set up your workspace');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.isStreaming && data.messages.length === 0;
        })()
    JS, true);

    expect(TurnPresence::current($conversationId))->not->toBeNull();

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->conversationId === $conversationId
        && $job->origin === MessageOrigin::Greeting);
});

it('approves a seeded proposal in the setup thread and completes the first-record step', function (): void {
    Queue::fake();

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
