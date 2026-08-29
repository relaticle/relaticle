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
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

function panelUrl(string $slug, string $segment, string $id): string
{
    return "https://consolidate-ask-relaticle.test/app/{$slug}/{$segment}/{$id}";
}

it('populates record context from a url while the panel is closed', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext', panelUrl($this->team->slug, 'companies', (string) $company->getKey()))
        ->assertSet('isOpen', false)
        ->assertSet('recordType', 'company')
        ->assertSet('recordId', (string) $company->getKey())
        ->assertSet('recordName', 'Acme');
});

it('clears record context when the url has no record', function (): void {
    Livewire::test(ChatSidePanel::class)
        ->set('recordType', 'company')
        ->set('recordId', 'stale-id')
        ->call('refreshContext', "https://consolidate-ask-relaticle.test/app/{$this->team->slug}/companies")
        ->assertSet('recordType', null)
        ->assertSet('recordId', null);
});

it('refuses a url pointing at another team record', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $theirs = Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Theirs']);

    Livewire::test(ChatSidePanel::class)
        ->call('refreshContext', panelUrl($this->team->slug, 'companies', (string) $theirs->getKey()))
        ->assertSet('recordType', null)
        ->assertSet('recordName', null);
});

it('dispatches the context-updated browser event with the resolved record', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Manch Minasyan']);

    Livewire::test(ChatSidePanel::class)
        ->call('refreshContext', panelUrl($this->team->slug, 'people', (string) $person->getKey()))
        ->assertDispatched('chat:context-updated');
});
