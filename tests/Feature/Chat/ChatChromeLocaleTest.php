<?php

declare(strict_types=1);

use App\Livewire\App\Profile\UpdateProfileInformation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Tests\Helpers\FakeTranslations;
use Tests\Helpers\ProposalCardFixture;

mutates(ChatInterface::class, ProposalCard::class, ChatSidePanel::class, ChatAllChatsPanel::class, ChatSidebarNav::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create(['locale' => 'da']);
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    $this->conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('renders chat chrome in the user\'s locale and restores English after', function (string $class, string $key, string $translatedText): void {
    FakeTranslations::inLocale('da', [$key => $translatedText]);

    $params = $class === ChatInterface::class ? ['conversationId' => $this->conversationId] : [];

    Livewire::test($class, $params)->assertSee($translatedText);

    expect(app()->getLocale())->toBe('en');
})->with([
    'ChatInterface' => [ChatInterface::class, 'Ask anything...', 'Skriv her...'],
    'ChatSidePanel' => [ChatSidePanel::class, 'Chat side panel', 'Chatpanel paa dansk'],
    'ChatAllChatsPanel' => [ChatAllChatsPanel::class, 'All chats', 'Alle chats'],
    'ChatSidebarNav' => [ChatSidebarNav::class, 'Chats', 'Samtaler'],
]);

it('renders an active proposal card in the user\'s locale', function (): void {
    FakeTranslations::inLocale('da', ['Discard' => 'Kassere']);

    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSee('Kassere')
        ->assertDontSee('Discard');
});

it('leaves a non-chat component in the app locale', function (): void {
    Lang::addLines(['profile.actions.save' => 'Gem'], 'da');

    Livewire::test(UpdateProfileInformation::class)->assertDontSee('Gem');

    expect(__('profile.actions.save', [], 'da'))->toBe('Gem');
});
