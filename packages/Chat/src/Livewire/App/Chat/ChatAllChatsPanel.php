<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Filament\Pages\ChatConversation;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Actions\SearchConversations;

final class ChatAllChatsPanel extends BaseLivewireComponent
{
    public bool $isOpen = false;

    public string $search = '';

    /** @var array<string, string> */
    protected $listeners = [
        'chat:open-all-chats' => 'open',
        'chat:close-all-chats' => 'close',
        'chat:conversation-created' => '$refresh',
        'chat:conversation-deleted' => '$refresh',
        'chat:conversation-renamed' => '$refresh',
    ];

    public function open(): void
    {
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->search = '';
    }

    public function deleteConversation(string $conversationId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $deleted = (new DeleteConversation)->execute($user, $conversationId);

        if ($deleted) {
            $this->dispatch('chat:conversation-deleted');
        }
    }

    public function render(): View
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return view('chat::components.empty-container');
        }

        $query = trim($this->search);

        /** @var Collection<int, \stdClass> $conversations */
        $conversations = $query === ''
            ? (new ListConversations)->execute($user, 50)
            : (new SearchConversations)->execute($user, $query);

        return view('chat::livewire.app.chat.chat-all-chats-panel', [
            'conversations' => $conversations,
            'newChatUrl' => ChatConversation::getUrl(),
            'isSearching' => $query !== '',
        ]);
    }
}
