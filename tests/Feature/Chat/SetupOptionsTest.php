<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\OnboardingDataSource;
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

function answeredWith(?OnboardingDataSource $source): Workspace
{
    test()->workspace->forceFill(['onboarding_data_source' => $source])->save();

    return test()->workspace->fresh();
}

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

it('offers the memory buttons to an in my head workspace', function (): void {
    answeredWith(OnboardingDataSource::Memory);

    $options = resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh());

    expect(array_map(fn (SetupOption $option): string => $option->id, $options))->toBe(['add_client', 'add_deal'])
        ->and(array_map(fn (SetupOption $option): string => $option->kind, $options))->toBe(['prompt', 'prompt'])
        ->and($options[0]->prompt)->toBeString()
        ->and($options[0]->url)->toBeNull();
});

it('offers the memory buttons to a workspace that never answered', function (): void {
    answeredWith(null);

    expect(optionIds())->toBe(['add_client', 'add_deal']);
});

it('offers the inbox buttons once a mailbox is connected', function (): void {
    answeredWith(OnboardingDataSource::Inbox);
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey()]);

    expect(optionIds())->toBe(['show_synced', 'add_outside_email']);
});

it('offers the memory buttons to an inbox workspace whose mailbox step was skipped', function (): void {
    answeredWith(OnboardingDataSource::Inbox);

    expect(optionIds())->toBe(['add_client', 'add_deal']);
});

it('offers the assistant buttons with a prompt and a link to the connection steps', function (): void {
    answeredWith(OnboardingDataSource::Assistant);

    $options = resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh());

    expect(array_map(fn (SetupOption $option): string => $option->id, $options))->toBe(['copy_prompt', 'connect_steps'])
        ->and($options[0]->kind)->toBe('prompt')
        ->and($options[0]->prompt)->toContain('prompt')
        ->and($options[1]->kind)->toBe('link')
        ->and($options[1]->url)->toBe(resolve(DestinationResolver::class)->resolve('connect_assistant', $this->workspace))
        ->and($options[1]->prompt)->toBeNull();
});

it('offers the upload link to a file answer while nothing has arrived', function (OnboardingDataSource $source): void {
    answeredWith($source);

    $options = resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh());

    expect(array_map(fn (SetupOption $option): string => $option->id, $options))->toBe(['upload_file'])
        ->and($options[0]->kind)->toBe('link')
        ->and($options[0]->url)->toBe(resolve(DestinationResolver::class)->resolve('import_people', $this->workspace));
})->with([OnboardingDataSource::Spreadsheet, OnboardingDataSource::OtherCrm]);

it('offers pick who to work on first once records arrived today', function (OnboardingDataSource $source, CreationSource $arrivedFrom): void {
    answeredWith($source);
    arrivedFrom($arrivedFrom);

    expect(optionIds())->toBe(['pick_first', 'later']);
})->with([
    'spreadsheet import' => [OnboardingDataSource::Spreadsheet, CreationSource::IMPORT],
    'other crm import' => [OnboardingDataSource::OtherCrm, CreationSource::IMPORT],
    'inbox mailbox' => [OnboardingDataSource::Inbox, CreationSource::MAILBOX],
    'memory import' => [OnboardingDataSource::Memory, CreationSource::IMPORT],
    'assistant mailbox' => [OnboardingDataSource::Assistant, CreationSource::MAILBOX],
]);

it('does not offer the company pick when only people arrived', function (): void {
    answeredWith(OnboardingDataSource::Spreadsheet);
    peopleArrivedFrom(CreationSource::IMPORT);

    expect(optionIds())->toBe(['upload_file']);
});

it('offers the memory buttons to an inbox answer with no mailbox when a file import brought companies', function (): void {
    answeredWith(OnboardingDataSource::Inbox);
    arrivedFrom(CreationSource::IMPORT);

    expect(optionIds())->toBe(['pick_first', 'later']);
});

it('ignores records that arrived from a source the answer does not bring in', function (): void {
    answeredWith(OnboardingDataSource::Spreadsheet);
    arrivedFrom(CreationSource::MAILBOX);
    arrivedFrom(CreationSource::SAMPLE);

    expect(optionIds())->toBe(['upload_file']);
});

it('ignores records that arrived before the owner day began', function (): void {
    $this->travelTo('2026-11-08 02:00:00');
    $this->user->forceFill(['timezone' => 'America/New_York'])->save();
    answeredWith(OnboardingDataSource::Spreadsheet);
    Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creation_source' => CreationSource::IMPORT, 'created_at' => '2026-11-07 03:00:00']);

    expect(optionIds())->toBe(['upload_file']);
});

it('never offers an import button to an owner without data import', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    answeredWith(OnboardingDataSource::Spreadsheet);

    expect(optionIds($viewer->fresh()))->toBe([])
        ->and(optionIds($this->user))->toBe(['upload_file']);
});

it('keeps the link that needs no capability for a user without data import', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    answeredWith(OnboardingDataSource::Assistant);

    expect(optionIds($viewer->fresh()))->toBe(['copy_prompt', 'connect_steps']);
});

it('drops a link whose page is unavailable', function (): void {
    answeredWith(OnboardingDataSource::Assistant);
    config()->set('documentation.public_url', null);

    expect(resolve(DestinationResolver::class)->resolve('connect_assistant', $this->workspace))->toBeNull()
        ->and(optionIds())->toBe(['copy_prompt']);
});

it('never links to the email accounts page', function (bool $emailIntegration): void {
    config()->set('relaticle.features.email_integration', $emailIntegration);
    Feature::flushCache();

    $emailAccounts = resolve(DestinationResolver::class)->resolve('email_accounts', $this->workspace);
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey()]);

    foreach (OnboardingDataSource::cases() as $source) {
        answeredWith($source);

        foreach ([false, true] as $arrived) {
            $arrived && arrivedFrom(CreationSource::IMPORT);

            foreach (resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh()) as $option) {
                expect($option->url === null || $option->url !== $emailAccounts)->toBeTrue();
            }
        }
    }
})->with([false, true]);

it('keeps every label and prompt short, in the app words, with no dash', function (): void {
    $this->travelTo('2026-11-08 12:00:00');
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey()]);
    $options = [];

    foreach (OnboardingDataSource::cases() as $source) {
        answeredWith($source);
        $options = [...$options, ...resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh())];
    }

    arrivedFrom(CreationSource::IMPORT);
    $options = [...$options, ...resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh())];

    expect($options)->not->toBeEmpty();

    foreach ($options as $option) {
        expect(mb_strlen($option->label))->toBeLessThanOrEqual(48)
            ->and(mb_strlen((string) $option->prompt))->toBeLessThanOrEqual(160)
            ->and($option->label.$option->prompt)->not->toContain('—')->not->toContain('contact')
            ->and($option->kind === 'prompt' ? $option->prompt : $option->url)->not->toBeEmpty()
            ->and($option->icon)->toStartWith('lucide-');
    }
});

it('serialises an option to the shape the transcript reads', function (): void {
    answeredWith(OnboardingDataSource::Memory);

    expect(resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh())[0]->toArray())->toBe([
        'id' => 'add_client',
        'kind' => 'prompt',
        'label' => 'Add a client',
        'prompt' => __('onboarding/setup.buttons.add_client.prompt'),
        'url' => null,
        'icon' => 'lucide-building-2',
    ]);
});

function runSetupTurn(User $user, Workspace $workspace, MessageOrigin $origin = MessageOrigin::Typed): void
{
    (new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: $origin->isTyped() ? 'Hello' : '',
        conversationId: $workspace->setupConversation->id,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: $origin,
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
    answeredWith(OnboardingDataSource::Memory);
    CrmAssistant::fake(['Hi, your workspace is ready.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::Greeting);

    $expected = expectedOptionArrays();

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toEqual($expected);

    Queue::assertNotPushed(SuggestNextSteps::class);
    Event::assertDispatched(
        NextStepsSuggested::class,
        fn (NextStepsSuggested $event): bool => $event->conversationId === $this->workspace->setupConversation->id
            && $event->steps === $expected,
    );
});

it('writes setup buttons on the data arrived turn', function (): void {
    suggestNextStepsFake();
    answeredWith(OnboardingDataSource::Spreadsheet);
    arrivedFrom(CreationSource::IMPORT);
    CrmAssistant::fake(['Your companies arrived.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::DataArrived);

    expect(array_column(lastAssistantNextSteps($this->workspace->setupConversation->id), 'id'))->toBe(['pick_first', 'later']);
    Queue::assertNotPushed(SuggestNextSteps::class);
});

it('keeps suggested next steps for a typed turn in the setup chat', function (): void {
    suggestNextStepsFake();
    answeredWith(OnboardingDataSource::Memory);
    CrmAssistant::fake(['Tell me who you work with.']);

    runSetupTurn($this->user, $this->workspace->fresh());

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
    Queue::assertPushed(SuggestNextSteps::class);
});

it('keeps suggested next steps for a workspace without a data source', function (MessageOrigin $origin): void {
    suggestNextStepsFake();
    answeredWith(null);
    CrmAssistant::fake(['Hi, your workspace is ready.']);

    runSetupTurn($this->user, $this->workspace->fresh(), $origin);

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
    Queue::assertPushed(SuggestNextSteps::class);
})->with([MessageOrigin::Greeting, MessageOrigin::DataArrived]);

it('writes no setup buttons when next steps are switched off', function (): void {
    suggestNextStepsFake();
    config()->set('chat.next_steps.enabled', false);
    answeredWith(OnboardingDataSource::Memory);
    CrmAssistant::fake(['Hi, your workspace is ready.']);

    runSetupTurn($this->user, $this->workspace->fresh(), MessageOrigin::Greeting);

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
});

it('writes nothing for an answer that has no button to offer', function (): void {
    suggestNextStepsFake();
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    answeredWith(OnboardingDataSource::Spreadsheet);
    CrmAssistant::fake(['Ask your workspace owner to upload the file.']);

    runSetupTurn($viewer->fresh(), $this->workspace->fresh(), MessageOrigin::Greeting);

    expect(lastAssistantNextSteps($this->workspace->setupConversation->id))->toBeNull();
});

it('keeps suggesting next steps in an ordinary conversation', function (): void {
    Queue::fake();
    answeredWith(OnboardingDataSource::Memory);
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

it('draws every option with an icon the chat ships', function (): void {
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey()]);

    $icons = [];

    foreach (OnboardingDataSource::cases() as $source) {
        answeredWith($source);

        foreach (resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh()) as $option) {
            $icons[] = $option->icon;
        }
    }

    arrivedFrom(CreationSource::IMPORT);

    foreach (resolve(SetupOptions::class)->for($this->user, $this->workspace->fresh()) as $option) {
        $icons[] = $option->icon;
    }

    expect(array_values(array_unique($icons)))->not->toBeEmpty()
        ->and(array_diff($icons, SetupOption::ICONS))->toBe([]);
});
