<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Services\ChatContextService;

final class ChatSidePanel extends BaseLivewireComponent
{
    /**
     * Stands in for the conversation id while building the full-page chat URL.
     * The panel only learns the id of a brand-new conversation client-side, so
     * the header menu swaps this out in the browser instead of round-tripping.
     */
    private const string CONVERSATION_URL_PLACEHOLDER = '__CONVERSATION_ID__';

    public bool $isOpen = false;

    public ?string $conversationId = null;

    public ?string $recordType = null;

    public ?string $recordId = null;

    public ?string $recordName = null;

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'chat:open-panel' => 'openPanel',
        'chat:close-panel' => 'closePanel',
        'chat:toggle-panel' => 'togglePanel',
    ];

    public function mount(): void
    {
        $this->refreshContext(request()->fullUrl());
    }

    public function openPanel(?string $conversationId = null): void
    {
        $this->isOpen = true;

        if ($conversationId !== null) {
            $this->conversationId = $conversationId;
        }
    }

    public function closePanel(): void
    {
        $this->isOpen = false;
    }

    public function togglePanel(): void
    {
        $this->isOpen = ! $this->isOpen;
    }

    /**
     * Resolve context for a URL supplied by the browser.
     *
     * Null means "no URL available" (a direct call outside a page context),
     * which clears the binding rather than guessing.
     */
    public function refreshContext(?string $url = null): void
    {
        $contextService = resolve(ChatContextService::class);

        $context = $url === null
            ? ['record_type' => null, 'record_id' => null, 'record_name' => null]
            : $contextService->getContextForUrl($url);

        $this->recordType = $context['record_type'];
        $this->recordId = $context['record_id'];
        $this->recordName = $context['record_name'];
        $this->dispatch(
            'chat:context-updated',
            type: $this->recordType,
            id: $this->recordId,
            label: $this->recordName,
        );
    }

    /**
     * Load an existing conversation into the panel. The embedded chat interface
     * is keyed by conversation, so changing this remounts it against the picked
     * transcript.
     */
    public function openConversation(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Start a fresh transcript in the panel, leaving the record context intact.
     */
    public function startNewConversation(): void
    {
        $this->conversationId = null;
    }

    public function deleteConversation(string $conversationId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if (! (new DeleteConversation)->execute($user, $conversationId)) {
            return;
        }

        if ($this->conversationId === $conversationId) {
            $this->conversationId = null;
        }

        $this->dispatch('chat:conversation-deleted');
    }

    public function render(): View
    {
        /**
         * The panel also renders on tenant-less pages (workspace creation, email
         * verification), where the chat routes have no URL to resolve — the
         * header's full-page link hides itself when these are null.
         */
        $tenant = Filament::getTenant();

        return view('chat::livewire.app.chat.chat-side-panel', [
            /** Home is where a chat starts; the full-page chat only shows saved transcripts. */
            'newChatUrl' => $tenant === null
                ? null
                : Dashboard::getUrl(panel: 'app', tenant: $tenant),
            'conversationUrlTemplate' => $tenant === null
                ? null
                : ChatConversation::getUrl(['conversationId' => self::CONVERSATION_URL_PLACEHOLDER], panel: 'app', tenant: $tenant),
            'conversationUrlPlaceholder' => self::CONVERSATION_URL_PLACEHOLDER,
        ]);
    }
}
