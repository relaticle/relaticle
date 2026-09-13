<?php

declare(strict_types=1);

use App\Actions\Onboarding\CreateSetupConversation;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Listeners\CreateSetupConversationListener;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\ConversationTitleGate;
use Relaticle\Chat\Support\SetupOpener;

mutates(CreateSetupConversation::class, CreateSetupConversationListener::class, SetupOpener::class, SupersededAwareConversationStore::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);
});

function personalWorkspaceFor(User $user, array $attributes = []): Workspace
{
    $workspace = Workspace::factory()->create([
        'user_id' => $user->getKey(),
        'personal_workspace' => true,
        ...$attributes,
    ]);

    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    return $workspace->fresh();
}

it('seeds one setup conversation with a templated opener when the wizard creates a workspace', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Northwind',
            'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
            'onboarding_context' => ['sourcing'],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();
    $conversation = $workspace->setupConversation;

    expect($conversation)->toBeInstanceOf(AgentConversation::class)
        ->and($conversation->title)->toBe('Set up your workspace')
        ->and($conversation->participant_id)->toBe((string) $user->getKey())
        ->and($conversation->participant_type)->toBe('user');

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversation->id)->get();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role)->toBe('assistant')
        ->and($messages->first()->participant_id)->toBe((string) $user->getKey())
        ->and(json_decode((string) $messages->first()->meta, true))->toBe(['kind' => 'setup_opener'])
        ->and($messages->first()->content)->toContain('Your candidate pipeline is ready: Sourced through Hired.');
});

it('seeds nothing when the flag is off', function (): void {
    Feature::define(SetupConversation::class, false);
    $user = User::factory()->create();

    personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(AgentConversation::query()->setup()->exists())->toBeFalse();
});

it('seeds nothing for a workspace that is not the personal one', function (): void {
    $user = User::factory()->create();

    Workspace::factory()->create(['user_id' => $user->getKey(), 'personal_workspace' => false, 'onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(AgentConversation::query()->setup()->exists())->toBeFalse();
});

it('composes the use-case line for every use case', function (OnboardingUseCase $useCase, ?string $other, string $line): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => $useCase, 'onboarding_other_use_case' => $other]);

    expect(resolve(SetupOpener::class)->useCaseLine($workspace))->toBe($line);
})->with([
    'sales' => [OnboardingUseCase::Sales, null, 'Your pipeline is ready: Prospecting through Closed Won.'],
    'marketing' => [OnboardingUseCase::Marketing, null, 'Your pipeline is ready: Prospecting through Closed Won.'],
    'customer success' => [OnboardingUseCase::CustomerSuccess, null, 'Your accounts board is ready: Onboarding through Renewed.'],
    'recruiting' => [OnboardingUseCase::Recruiting, null, 'Your candidate pipeline is ready: Sourced through Hired.'],
    'fundraising' => [OnboardingUseCase::Fundraising, null, 'Your investor pipeline is ready: Target through Closed.'],
    'investing' => [OnboardingUseCase::Investing, null, 'Your deal flow is ready: Target through Closed.'],
    'other with text' => [OnboardingUseCase::Other, 'Field research', 'Your workspace is ready for tracking Field research.'],
    'other without text' => [OnboardingUseCase::Other, null, 'Your workspace is ready.'],
]);

it('falls back to the plain line when the workspace has no use case', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user);

    expect(resolve(SetupOpener::class)->useCaseLine($workspace))->toBe('Your workspace is ready.');
});

it('always carries the data paragraph and the self-hosting link', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);

    $opener = resolve(SetupOpener::class)->compose($workspace);

    expect($opener)
        ->toContain('Paste your contacts here or attach the file, any columns, any order.')
        ->toContain('No list yet? Tell me about three people')
        ->toContain('[self-hosting guide](')
        ->toContain('/developers/self-hosting')
        ->not->toContain('Claude or ChatGPT');
});

it('adds the connect line only when the workspace was referred by an AI assistant', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, [
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_referral_source' => OnboardingReferralSource::AI,
    ]);

    $opener = resolve(SetupOpener::class)->compose($workspace);

    expect($opener)
        ->toContain('You can also work from Claude or ChatGPT directly: [connect your assistant](')
        ->toContain('/help/ai-assistant/connect-claude-or-chatgpt');
});

it('keeps markdown out of the Other text', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, [
        'onboarding_use_case' => OnboardingUseCase::Other,
        'onboarding_other_use_case' => '[evil](https://evil.test) *bold* `code`',
    ]);

    $line = resolve(SetupOpener::class)->useCaseLine($workspace);

    expect($line)->toBe('Your workspace is ready for tracking evilhttps://evil.test bold code.')
        ->and($line)->not->toContain('[')
        ->and($line)->not->toContain('*');
});

it('never seeds a second setup conversation for the same workspace', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);

    resolve(CreateSetupConversation::class)->execute($workspace);
    resolve(CreateSetupConversation::class)->execute($workspace);

    expect(AgentConversation::query()->setup()->where('workspace_id', $workspace->getKey())->count())->toBe(1);
});

it('renders the opener in the transcript but hides it from the provider replay', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Recruiting]);
    $conversationId = $workspace->setupConversation->id;

    $transcript = resolve(ListConversationMessages::class)->execute($user, $conversationId);

    expect($transcript)->toHaveCount(1)
        ->and($transcript[0]['role'])->toBe('assistant')
        ->and($transcript[0]['content'])->toContain('Your candidate pipeline is ready');

    $replayed = resolve(ConversationStore::class)->getLatestConversationMessages($conversationId, 100);

    expect($replayed)->toHaveCount(0);
});

it('keeps the lang-file title when the first user message arrives', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Recruiting]);

    expect(ConversationTitleGate::beforeTurn($workspace->setupConversation->id, 'Here are my candidates'))->toBeNull()
        ->and($workspace->setupConversation->fresh()->title)->toBe('Set up your workspace');
});
