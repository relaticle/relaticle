<?php

declare(strict_types=1);

use App\Actions\Workspace\SetWorkspaceWebSearch;
use App\Livewire\App\Workspaces\WorkspaceWebSearchSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Actions\SetWebSearchPreference;
use Relaticle\Chat\Enums\WebSearchPreference;
use Relaticle\Chat\Filament\Pages\WebSearchSettings;

mutates(WebSearchSettings::class, WebSearchPreference::class, SetWebSearchPreference::class, WorkspaceWebSearchSettings::class, SetWorkspaceWebSearch::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

it('saves the web search preference without touching the default model', function (): void {
    $this->user->update(['ai_preferences' => ['default_model' => 'claude-sonnet-5']]);

    Livewire::test(WebSearchSettings::class)
        ->fillForm(['web_search' => WebSearchPreference::Allow->value])
        ->assertNotified();

    expect($this->user->fresh()->ai_preferences)
        ->toHaveCount(2)
        ->toHaveKey('default_model', 'claude-sonnet-5')
        ->toHaveKey('web_search', 'allow')
        ->and(WebSearchPreference::of($this->user->fresh()))->toBe(WebSearchPreference::Allow);
});

it('shows Always ask to a user who never chose', function (): void {
    Livewire::test(WebSearchSettings::class)
        ->assertOk()
        ->assertFormSet(['web_search' => 'ask'])
        ->assertSee('Always ask')
        ->assertSee('Always allow')
        ->assertSee("Don't allow")
        ->assertSee('Each search uses 2 credits.');
});

it('disables the preference when the workspace turned web search off', function (): void {
    $this->workspace->update(['ai_web_search_enabled' => false]);

    Livewire::test(WebSearchSettings::class)
        ->assertFormFieldDisabled('web_search')
        ->assertSee('Your workspace owner turned web search off.')
        ->fillForm(['web_search' => WebSearchPreference::Allow->value]);

    expect($this->user->fresh()->ai_preferences['web_search'] ?? null)->toBeNull();
});

it('lets only a workspace owner turn web search off', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member);

    Livewire::test(WorkspaceWebSearchSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['ai_web_search_enabled' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_web_search_enabled)->toBeTrue();

    $this->actingAs($this->user);

    Livewire::test(WorkspaceWebSearchSettings::class, ['workspace' => $this->workspace])
        ->assertFormSet(['ai_web_search_enabled' => true])
        ->fillForm(['ai_web_search_enabled' => false])
        ->call('updateWebSearch')
        ->assertNotified();

    expect($this->workspace->fresh()->ai_web_search_enabled)->toBeFalse();
});

it('refuses the owner of another workspace', function (): void {
    $otherOwner = User::factory()->withWorkspace()->create();

    $this->actingAs($otherOwner);

    Livewire::test(WorkspaceWebSearchSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['ai_web_search_enabled' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_web_search_enabled)->toBeTrue();
});

it('refuses an admin of the workspace', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => 'admin']);
    $admin->switchWorkspace($this->workspace);

    $this->actingAs($admin);

    Livewire::test(WorkspaceWebSearchSettings::class, ['workspace' => $this->workspace])
        ->fillForm(['ai_web_search_enabled' => false])
        ->call('updateWebSearch')
        ->assertForbidden();

    expect($this->workspace->fresh()->ai_web_search_enabled)->toBeTrue();
});
