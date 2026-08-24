<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ListConversations;

final class ChatSidebarNav extends BaseLivewireComponent
{
    /**
     * Deliberately listens to nothing.
     *
     * This component is nested inside Filament's sidebar, and a nested
     * component's own re-render never reaches the DOM here: the server renders
     * it and Livewire discards the html, so the list keeps whatever it showed
     * at page load. Only the parent's render paints it, which Filament exposes
     * as the `refresh-sidebar` event (Filament\Livewire\Sidebar::refresh).
     *
     * Worse than useless: a self-refresh in the SAME batch as a parent refresh
     * loses the parent's paint too, so a chat list that listened here stayed
     * stale even once the right event was dispatched alongside it. Everything
     * that changes this list therefore dispatches `refresh-sidebar` and nothing
     * else — see ChatSidebarNav::deleteConversation and the chat JS.
     */
    public function deleteConversation(string $conversationId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        (new DeleteConversation)->execute($user, $conversationId);

        $this->dispatch('chat:conversation-deleted');
        // This component cannot repaint itself: it is nested inside Filament's
        // sidebar, so its own re-render is discarded and only the parent's
        // reaches the DOM. Filament exposes that repaint as `refresh-sidebar`.
        $this->dispatch('refresh-sidebar');
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
        ]);
    }
}
