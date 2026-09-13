<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

mutates(ChatSidePanel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

function panelUrl(string $slug, string $segment, string $id): string
{
    return "https://consolidate-ask-relaticle.test/app/{$slug}/{$segment}/{$id}";
}

it('populates record context from a url while the panel is closed', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext', panelUrl($this->workspace->slug, 'companies', (string) $company->getKey()))
        ->assertSet('isOpen', false)
        ->assertSet('recordType', 'company')
        ->assertSet('recordId', (string) $company->getKey())
        ->assertSet('recordName', 'Acme');
});

it('clears record context when the url has no record', function (): void {
    Livewire::test(ChatSidePanel::class)
        ->set('recordType', 'company')
        ->set('recordId', 'stale-id')
        ->call('refreshContext', "https://consolidate-ask-relaticle.test/app/{$this->workspace->slug}/companies")
        ->assertSet('recordType', null)
        ->assertSet('recordId', null);
});

it('refuses a url pointing at another workspace record', function (): void {
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $theirs = Company::factory()->for($otherUser->currentWorkspace)->create(['name' => 'Theirs']);

    Livewire::test(ChatSidePanel::class)
        ->call('refreshContext', panelUrl($this->workspace->slug, 'companies', (string) $theirs->getKey()))
        ->assertSet('recordType', null)
        ->assertSet('recordName', null);
});

it('dispatches the context-updated browser event with the resolved record', function (): void {
    $person = People::factory()->for($this->workspace)->create(['name' => 'Manch Minasyan']);

    Livewire::test(ChatSidePanel::class)
        ->call('refreshContext', panelUrl($this->workspace->slug, 'people', (string) $person->getKey()))
        ->assertDispatched('chat:context-updated');
});
