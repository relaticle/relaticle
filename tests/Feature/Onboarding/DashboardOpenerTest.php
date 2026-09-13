<?php

declare(strict_types=1);

use App\Actions\Onboarding\DismissSetupOpener;
use App\Enums\CreationSource;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Features\SetupConversation;
use App\Filament\Pages\Dashboard;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AgentConversation;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(Dashboard::class, DismissSetupOpener::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->owner->getKey(),
        'personal_workspace' => true,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
    ]);
    $this->owner->ownedWorkspaces()->save($this->workspace);
    $this->owner->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();
    $this->workspace = $this->workspace->fresh();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

function insertSetupUserMessage(AgentConversation $conversation, User $user): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'x',
        'role' => 'user',
        'content' => 'Here are my candidates',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('shows the opener, the Not now link and the setup id for a fresh workspace', function (): void {
    $conversation = $this->workspace->setupConversation;

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', $conversation->id)
        ->assertSee('Your candidate pipeline is ready: Sourced through Hired.')
        ->assertSee('Not now')
        ->assertSeeHtml('data-setup-conversation-id="'.$conversation->id.'"')
        ->assertDontSee('Good morning')
        ->assertDontSee('Welcome,')
        ->assertDontSee('Continue setup');
});

it('labels the recent chat link Continue setup until the workspace has an own record', function (): void {
    insertSetupUserMessage($this->workspace->setupConversation, $this->owner);

    livewire(Dashboard::class)
        ->assertSet('recentChatIsSetup', true)
        ->assertSee('Continue setup');

    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);
    resolve(WorkspaceActivationFacts::class)->forget($this->workspace);

    livewire(Dashboard::class)
        ->assertSet('recentChatIsSetup', false)
        ->assertDontSee('Continue setup');
});

it('hides the opener after Not now and redirects to the dashboard', function (): void {
    livewire(Dashboard::class)
        ->call('dismissSetupOpener')
        ->assertRedirect(Dashboard::getUrl());

    expect($this->workspace->fresh()->onboarding_opener_dismissed_at)->not->toBeNull();

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', null)
        ->assertDontSee('Not now')
        ->assertDontSeeHtml('data-setup-conversation-id=');
});

it('hides the opener once the setup conversation holds a user message', function (): void {
    insertSetupUserMessage($this->workspace->setupConversation, $this->owner);

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', null)
        ->assertDontSee('Not now');
});

it('hides the opener when the flag is off even though the conversation exists', function (): void {
    Feature::define(SetupConversation::class, false);

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', null)
        ->assertSet('recentChatIsSetup', false)
        ->assertDontSee('Not now')
        ->assertDontSee('Continue setup');
});

it('never shows the opener to an invited member', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();

    $this->actingAs($member);

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', null)
        ->assertDontSee('Not now')
        ->assertDontSee('Continue setup');
});

it('never shows the opener on a second workspace', function (): void {
    $second = Workspace::factory()->create(['user_id' => $this->owner->getKey(), 'personal_workspace' => false]);
    $this->owner->ownedWorkspaces()->save($second);
    $this->owner->forceFill(['current_workspace_id' => $second->getKey()])->save();
    Filament::setTenant($second);

    livewire(Dashboard::class)
        ->assertSet('setupConversationId', null)
        ->assertDontSee('Not now');
});

it('refuses Not now from a member', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    resolve(DismissSetupOpener::class)->execute($member, $this->workspace);
})->throws(HttpException::class);
