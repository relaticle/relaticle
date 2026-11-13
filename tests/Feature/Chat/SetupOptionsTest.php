<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Features\SetupConversation;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Data\SetupOption;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Events\NextStepsSuggested;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Jobs\SuggestNextSteps;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\SetupOptions;
use Relaticle\Chat\Support\DestinationResolver;
use Relaticle\Chat\Support\NextSteps;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(SetupOptions::class, NextSteps::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);

    $this->user = User::factory()->withPersonalWorkspace(function (Workspace $workspace): void {
        $workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Sales])->save();
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

/**
 * @return list<string>
 */
function optionIds(?User $user = null, ?Workspace $workspace = null): array
{
    return array_map(
        fn (SetupOption $option): string => $option->id,
        resolve(SetupOptions::class)->for($user ?? test()->user, $workspace ?? test()->workspace->fresh()),
    );
}

function arrivedFrom(CreationSource $source): void
{
    Company::factory()->create(['workspace_id' => test()->workspace->getKey(), 'creator_id' => null, 'creation_source' => $source]);
}

function peopleArrivedFrom(CreationSource $source): void
{
    People::factory()->create(['workspace_id' => test()->workspace->getKey(), 'creator_id' => null, 'creation_source' => $source]);
}

function connectMailboxFor(User $user): void
{
    ConnectedAccount::factory()->create(['workspace_id' => test()->workspace->getKey(), 'user_id' => $user->getKey()]);
}

it('offers three ways in to an owner with no mailbox', function (): void {
    $options = resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh());

    expect(array_map(fn (SetupOption $option): string => $option->id, $options))->toBe(['upload_file', 'add_client', 'connect_assistant'])
        ->and(array_map(fn (SetupOption $option): string => $option->kind, $options))->toBe(['link', 'prompt', 'prompt'])
        ->and($options[0]->url)->toBe(resolve(DestinationResolver::class)->resolve('import_people', $this->workspace))
        ->and($options[1]->prompt)->toBeString()
        ->and($options[1]->url)->toBeNull()
        ->and($options[2]->prompt)->toBe(__('onboarding/setup.buttons.connect_assistant.prompt'))
        ->and($options[2]->url)->toBeNull();
});

it('offers the mailbox buttons once the owner connected a mailbox', function (): void {
    connectMailboxFor($this->user);

    expect(optionIds())->toBe(['show_synced', 'add_outside_email']);
});

it('reads the mailbox of the person in the chat, not a teammate\'s', function (): void {
    $teammate = User::factory()->create();
    $this->workspace->users()->attach($teammate, ['role' => WorkspaceRole::Member->value]);
    connectMailboxFor($teammate);

    expect(optionIds())->toBe(['upload_file', 'add_client', 'connect_assistant']);
});

it('offers the company pick once companies arrived today', function (bool $mailboxConnected, CreationSource $arrivedFrom): void {
    if ($mailboxConnected) {
        connectMailboxFor($this->user);
    }

    arrivedFrom($arrivedFrom);

    expect(optionIds())->toBe(['pick_first', 'later']);
})->with([
    'no mailbox, import' => [false, CreationSource::IMPORT],
    'no mailbox, mailbox records' => [false, CreationSource::MAILBOX],
    'no mailbox, assistant records' => [false, CreationSource::MCP],
    'mailbox, import' => [true, CreationSource::IMPORT],
    'mailbox, mailbox records' => [true, CreationSource::MAILBOX],
]);

it('does not offer the company pick when only people arrived', function (): void {
    peopleArrivedFrom(CreationSource::IMPORT);

    expect(optionIds())->toBe(['upload_file', 'add_client', 'connect_assistant']);
});

it('does not offer the company pick for sample or hand-made companies', function (CreationSource $source): void {
    arrivedFrom($source);

    expect(optionIds())->toBe(['upload_file', 'add_client', 'connect_assistant']);
})->with([CreationSource::SAMPLE, CreationSource::WEB, CreationSource::CHAT]);

it('ignores records that arrived before the owner day began', function (): void {
    $this->travelTo('2026-11-08 02:00:00');
    $this->user->forceFill(['timezone' => 'America/New_York'])->save();
    Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creation_source' => CreationSource::IMPORT, 'created_at' => '2026-11-07 03:00:00']);

    expect(optionIds())->toBe(['upload_file', 'add_client', 'connect_assistant']);
});

it('never offers an import button to a member without data import', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);

    expect(optionIds($viewer->fresh()))->toBe(['add_client', 'connect_assistant'])
        ->and(optionIds($this->user))->toBe(['upload_file', 'add_client', 'connect_assistant']);
});

it('keeps the connect option when the help page is unavailable', function (): void {
    config()->set('documentation.public_url', null);

    expect(resolve(DestinationResolver::class)->resolve('connect_assistant', $this->workspace))->toBeNull()
        ->and(optionIds())->toBe(['upload_file', 'add_client', 'connect_assistant']);
});

/**
 * @return list<SetupOption>
 */
function optionsInEveryState(): array
{
    $options = resolve(SetupOptions::class)->for(test()->user, test()->workspace->fresh());

    connectMailboxFor(test()->user);
    $options = [...$options, ...resolve(SetupOptions::class)->for(test()->user, test()->workspace->fresh())];

    arrivedFrom(CreationSource::IMPORT);

    return [...$options, ...resolve(SetupOptions::class)->for(test()->user, test()->workspace->fresh())];
}

it('never links to the email accounts page', function (): void {
    $emailAccounts = resolve(DestinationResolver::class)->resolve('email_accounts', $this->workspace);

    expect($emailAccounts)->toBeString()
        ->and(array_map(fn (SetupOption $option): ?string => $option->url, optionsInEveryState()))->not->toContain($emailAccounts);
});

it('keeps every label and prompt short, in the app words, with no dash', function (): void {
    $this->travelTo('2026-11-08 12:00:00');
    $options = optionsInEveryState();

    expect($options)->toHaveCount(7);

    foreach ($options as $option) {
        expect(mb_strlen($option->label))->toBeLessThanOrEqual(48)
            ->and(mb_strlen((string) $option->prompt))->toBeLessThanOrEqual(160)
            ->and($option->label.$option->prompt)->not->toContain('—')->not->toContain('contact')
            ->and($option->kind === 'prompt' ? $option->prompt : $option->url)->not->toBeEmpty()
            ->and($option->icon)->toStartWith('lucide-');
    }
});

it('draws every option with an icon the chat ships and ships no unused icon', function (): void {
    $icons = array_values(array_unique(array_map(fn (SetupOption $option): string => $option->icon, optionsInEveryState())));

    expect($icons)->toEqualCanonicalizing(SetupOption::ICONS);
});

it('serialises an option to the shape the transcript reads', function (): void {
    expect(resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh())[1]->toArray())->toBe([
        'id' => 'add_client',
        'kind' => 'prompt',
        'label' => 'Add a client',
        'prompt' => __('onboarding/setup.buttons.add_client.prompt'),
        'url' => null,
        'icon' => 'lucide-building-2',
    ]);
});

function runSetupTurn(User $user, Workspace $workspace, MessageOrigin $origin = MessageOrigin::Typed, ?string $nextStep = null): void
{
    (new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: $origin->isTyped() ? 'Hello' : '',
        conversationId: $workspace->setupConversation->id,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: $origin,
        nextStep: $nextStep,
    ))->handle(resolve(CreditService::class));
}

function lastAssistantNextSteps(string $conversationId): mixed
{
    $meta = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->latest('created_at')
        ->value('meta');

    return json_decode((string) $meta, true)['next_steps'] ?? null;
}

function suggestNextStepsFake(): void
{
    Queue::fake();
    Event::fake([NextStepsSuggested::class]);
}

function expectedOptionArrays(): array
{
    return array_map(
        fn (SetupOption $option): array => $option->toArray(),
        resolve(SetupOptions::class)->for(test()->user, test()->workspace->fresh()),
    );
}

it('writes setup buttons on the greeting turn', function (): void {
    suggestNextStepsFake();
    CrmAssistant::fake(['Hi, your workspace is ready.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::Greeting);

    $expected = expectedOptionArrays();

    expect(array_column($expected, 'id'))->toBe(['upload_file', 'add_client', 'connect_assistant'])
        ->and(lastAssistantNextSteps($this->workspace->setupConversation->id))->toEqual($expected);

    Queue::assertNotPushed(SuggestNextSteps::class);
    Event::assertDispatched(
        NextStepsSuggested::class,
        fn (NextStepsSuggested $event): bool => $event->conversationId === $this->workspace->setupConversation->id
            && $event->steps === $expected,
    );
});

it('writes the mailbox buttons on the greeting turn of an owner who connected a mailbox', function (): void {
    suggestNextStepsFake();
    connectMailboxFor($this->user);
    CrmAssistant::fake(['Hi, your mailbox is connected.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::Greeting);

    expect(array_column(lastAssistantNextSteps($this->workspace->setupConversation->id), 'id'))->toBe(['show_synced', 'add_outside_email']);
    Queue::assertNotPushed(SuggestNextSteps::class);
});

it('writes the company pick on the data arrived turn', function (CreationSource $arrivedFrom): void {
    suggestNextStepsFake();
    arrivedFrom($arrivedFrom);
    CrmAssistant::fake(['Your companies arrived.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::DataArrived);

    expect(array_column(lastAssistantNextSteps($this->workspace->setupConversation->id), 'id'))->toBe(['pick_first', 'later']);
    Queue::assertNotPushed(SuggestNextSteps::class);
})->with([CreationSource::IMPORT, CreationSource::MAILBOX]);

it('keeps suggested next steps for a typed turn in the setup chat once the workspace holds a record', function (?string $nextStep): void {
    suggestNextStepsFake();
    Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creation_source' => CreationSource::CHAT]);
    CrmAssistant::fake(['Tell me who you work with.']);

    runSetupTurn($this->user, $this->workspace->fresh(), nextStep: $nextStep);

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
    Queue::assertPushed(SuggestNextSteps::class);
})->with([
    'typed by hand' => [null],
    'sent by a setup button' => ['add_client'],
]);

it('writes only the ways in that need no typed name under a reply that asks for a company', function (?string $nextStep, array $ids): void {
    suggestNextStepsFake();
    CrmAssistant::fake(['Which company do you work with right now?']);

    runSetupTurn($this->user, $this->workspace->fresh(), nextStep: $nextStep);

    expect(array_column(lastAssistantNextSteps($this->workspace->setupConversation->id), 'id'))->toBe($ids);
    Queue::assertNotPushed(SuggestNextSteps::class);
})->with([
    'typed by hand' => [null, ['upload_file', 'connect_assistant']],
    'sent by add a client' => ['add_client', ['upload_file', 'connect_assistant']],
    'sent by the connect option' => ['connect_assistant', ['upload_file']],
]);

it('writes no chips under the ask on the turn that opens an ordinary conversation, and suggests again on the next', function (): void {
    suggestNextStepsFake();
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
    CrmAssistant::fake(['Which company do you work with right now?', 'Sure.']);

    $turn = fn () => (new ProcessChatMessage(
        user: $this->user,
        workspace: $this->workspace->fresh(),
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    ))->handle(resolve(CreditService::class));

    $turn();

    expect(lastAssistantNextSteps($conversationId))->toBeNull();
    Queue::assertNotPushed(SuggestNextSteps::class);

    $turn();

    Queue::assertPushed(SuggestNextSteps::class, 1);
});

it('writes no setup buttons when next steps are switched off', function (): void {
    suggestNextStepsFake();
    config()->set('chat.next_steps.enabled', false);
    CrmAssistant::fake(['Hi, your workspace is ready.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::Greeting);

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
});

it('keeps suggesting next steps in an ordinary conversation of a workspace that holds a record', function (): void {
    Queue::fake();
    Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creation_source' => CreationSource::CHAT]);
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

    (new ProcessChatMessage(
        user: $this->user,
        workspace: $this->workspace->fresh(),
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    Queue::assertPushed(SuggestNextSteps::class);
    expect(lastAssistantNextSteps($conversationId))->toBeNull();
});

it('reads next steps stored before buttons had a kind', function (): void {
    expect(NextSteps::fromMeta(['next_steps' => [['label' => 'A', 'prompt' => 'B']]]))->toBe([
        ['id' => null, 'kind' => 'prompt', 'label' => 'A', 'prompt' => 'B', 'url' => null, 'icon' => null],
    ]);
});

it('reads a stored link button and drops one without a url', function (): void {
    $steps = NextSteps::fromMeta(['next_steps' => [
        ['id' => 'upload_file', 'kind' => 'link', 'label' => 'Upload my file', 'prompt' => null, 'url' => 'https://app.test/import', 'icon' => 'lucide-upload'],
        ['id' => 'upload_file', 'kind' => 'link', 'label' => 'Broken', 'prompt' => null, 'url' => null, 'icon' => null],
        ['id' => 'x', 'kind' => 'prompt', 'label' => 'No prompt', 'prompt' => null, 'url' => null, 'icon' => null],
    ]]);

    expect($steps)->toBe([
        ['id' => 'upload_file', 'kind' => 'link', 'label' => 'Upload my file', 'prompt' => null, 'url' => 'https://app.test/import', 'icon' => 'lucide-upload'],
    ]);
});
