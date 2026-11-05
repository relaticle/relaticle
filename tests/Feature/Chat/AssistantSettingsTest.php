<?php

declare(strict_types=1);

use App\Actions\Workspace\SetWorkspaceWebSearch;
use App\Data\AiSettings;
use App\Livewire\App\Workspaces\WorkspaceAssistantSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Actions\SetWebSearchPreference;
use Relaticle\Chat\Enums\WebAccess;
use Relaticle\Chat\Enums\WebSearchPreference;
use Relaticle\Chat\Filament\Pages\AssistantSettings;
use Relaticle\Chat\Services\ModelRegistry;

mutates(AssistantSettings::class, AiSettings::class, WebAccess::class, WebSearchPreference::class, SetWebSearchPreference::class, WorkspaceAssistantSettings::class, SetWorkspaceWebSearch::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

it('saves the web search preference without touching the default model', function (): void {
    $this->user->update(['ai_preferences' => ['default_model' => 'claude-sonnet-5']]);

    Livewire::test(AssistantSettings::class)
        ->fillForm(['web_search' => WebSearchPreference::Allow->value])
        ->assertNotified();

    expect($this->user->fresh()->ai_preferences)
        ->toHaveCount(2)
        ->toHaveKey('default_model', 'claude-sonnet-5')
        ->toHaveKey('web_search', 'allow')
        ->and(WebSearchPreference::of($this->user->fresh()))->toBe(WebSearchPreference::Allow);
});

it('shows Always ask to a user who never chose', function (): void {
    Livewire::test(AssistantSettings::class)
        ->assertOk()
        ->assertFormSet(['web_search' => 'ask'])
        ->assertSee('Always ask')
        ->assertSee('Always allow')
        ->assertSee("Don't allow")
        ->assertSee("Search queries go to the AI provider's web search.");
});

it('names the search price only where the workspace meters credits', function (bool $metered): void {
    if ($metered) {
        withHostedBilling();
    }
    config(['chat.web_search_credit_cost' => 3]);

    $page = Livewire::test(AssistantSettings::class);

    $metered
        ? $page->assertSee('Each search uses 3 credits.')
        : $page->assertDontSee('Each search uses');
})->with(['metered' => true, 'unmetered' => false]);

it('disables the preference when the workspace turned web search off', function (): void {
    $this->workspace->update(['ai_settings' => ['web_search' => false]]);

    Livewire::test(AssistantSettings::class)
        ->assertFormFieldDisabled('web_search')
        ->assertSee('Your workspace owner turned web search off.')
        ->fillForm(['web_search' => WebSearchPreference::Allow->value]);

    expect($this->user->fresh()->ai_preferences['web_search'] ?? null)->toBeNull();
});

it('lets only a workspace owner turn web search off', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member);

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['web_search' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_settings->webSearch)->toBeTrue();

    $this->actingAs($this->user);

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->assertFormSet(['web_search' => true])
        ->fillForm(['web_search' => false])
        ->call('updateWebSearch')
        ->assertNotified();

    expect($this->workspace->fresh()->ai_settings->webSearch)->toBeFalse();
});

it('stores the owner web search choice under its snake case key', function (): void {
    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['web_search' => false])
        ->call('updateWebSearch');

    expect(json_decode((string) $this->workspace->fresh()->getRawOriginal('ai_settings'), true))->toBe(['web_search' => false]);
});

it('refuses the owner of another workspace', function (): void {
    $otherOwner = User::factory()->withWorkspace()->create();

    $this->actingAs($otherOwner);

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['web_search' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_settings->webSearch)->toBeTrue();
});

it('refuses an admin of the workspace', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => 'admin']);
    $admin->switchWorkspace($this->workspace);

    $this->actingAs($admin);

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['web_search' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_settings->webSearch)->toBeTrue();
});

it('disables both web search controls when no model on the install can search', function (): void {
    config()->set('ai.providers.anthropic.key', '');
    config()->set('ai.providers.openai.key', '');
    config()->set('chat.self_hosted.url', 'http://127.0.0.1:11434/v1');
    config()->set('chat.self_hosted.models', 'qwen3:8b');
    config()->set('ai.providers.selfhosted.url', 'http://127.0.0.1:11434/v1');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(AssistantSettings::class)
        ->assertFormFieldDisabled('web_search')
        ->assertSee('No model on this install can search the web.');

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->assertFormFieldDisabled('web_search')
        ->assertSee('No model on this install can search the web.');
});

it('keeps the web search controls enabled while one web model is available', function (): void {
    config()->set('ai.providers.anthropic.key', '');
    config()->set('ai.providers.openai.key', 'sk-test');

    Livewire::test(AssistantSettings::class)
        ->assertFormFieldEnabled('web_search')
        ->assertDontSee('No model on this install can search the web.');

    Livewire::test(WorkspaceAssistantSettings::class, ['workspace' => $this->workspace])
        ->assertFormFieldEnabled('web_search');
});
