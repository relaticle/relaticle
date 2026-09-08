<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

mutates(ChatInterface::class, ChatSidePanel::class, ChatAllChatsPanel::class, ChatSidebarNav::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create(['locale' => 'da']);
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    $this->conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function fakeChatChromeTranslation(string $locale, string $key, string $value): void
{
    $directory = sys_get_temp_dir().'/chat-locale-test-'.Str::random(8);

    mkdir($directory);
    file_put_contents($directory.'/'.$locale.'.json', json_encode([$key => $value], JSON_THROW_ON_ERROR));

    resolve(Translator::class)->addJsonPath($directory);
}

it('renders chat chrome in the user\'s locale and restores English after', function (string $class, string $key, string $translatedText): void {
    fakeChatChromeTranslation('da', $key, $translatedText);

    $params = $class === ChatInterface::class ? ['conversationId' => $this->conversationId] : [];

    Livewire::test($class, $params)->assertSee($translatedText);

    expect(app()->getLocale())->toBe('en');
})->with([
    'ChatInterface' => [ChatInterface::class, 'Ask anything...', 'Skriv her...'],
    'ChatSidePanel' => [ChatSidePanel::class, 'Chat side panel', 'Chatpanel paa dansk'],
    'ChatAllChatsPanel' => [ChatAllChatsPanel::class, 'All chats', 'Alle chats'],
    'ChatSidebarNav' => [ChatSidebarNav::class, 'Chats', 'Samtaler'],
]);
