<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Relaticle\Chat\Actions\FindConversation;

final class ChatConversation extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $slug = 'chats/{conversationId?}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'chat::filament.pages.chat-conversation';

    public ?string $conversationId = null;

    public ?string $initialMessage = null;

    public ?string $initialModel = null;

    public ?string $conversationTitle = null;

    public function mount(?string $conversationId = null): void
    {
        $this->conversationId = $conversationId;

        if ($this->conversationId) {
            /** @var User $user */
            $user = Filament::auth()->user();

            $this->conversationTitle = (new FindConversation)
                ->execute($user, $this->conversationId)?->title;
        }

        $this->initialMessage = null;
        $this->initialModel = null;
    }

    public function getTitle(): string
    {
        return $this->conversationTitle ?? 'New chat';
    }

    public function getHeading(): string
    {
        return $this->conversationTitle ?? 'New chat';
    }

    public static function getNavigationLabel(): string
    {
        return 'Chat';
    }
}
