<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Filament\Pages\ChatConversation;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ListConversations;

final class ChatSidebarNav extends BaseLivewireComponent
{
    /** @var array<string, string> */
    protected $listeners = [
        'chat:conversation-created' => '$refresh',
        'chat:conversation-deleted' => '$refresh',
        'chat:conversation-renamed' => '$refresh',
    ];

    public function deleteConversation(string $conversationId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        (new DeleteConversation)->execute($user, $conversationId);

        $this->dispatch('chat:conversation-deleted');
    }

    private const int SIDEBAR_LIMIT = 7;

    public function render(): View
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return view('chat::components.empty-container');
        }

        $conversations = (new ListConversations)->execute($user, self::SIDEBAR_LIMIT + 1);
        $hasMore = $conversations->count() > self::SIDEBAR_LIMIT;

        return view('chat::livewire.app.chat.chat-sidebar-nav', [
            'conversations' => $conversations->take(self::SIDEBAR_LIMIT),
            'hasMore' => $hasMore,
            'newChatUrl' => ChatConversation::getUrl(),
        ]);
    }
}
