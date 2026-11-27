<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;
use Tests\Helpers\ChatBrowser;

mutates(ChatSidebarNav::class);

it('keeps the open conversation active in the sidebar after the sidebar refreshes', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Pipeline review');
    $isActive = "document.querySelector('.fi-active a[href$=\"/chats/{$conversationId}\"]') !== null";

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId);
    $page->script("window.Alpine.store('sidebar').open()");
    $page->wait(0.5)->assertScript($isActive, true);

    $page->script("window.Livewire.dispatch('refresh-sidebar')");
    $page->wait(1.5)
        ->assertScript($isActive, true)
        ->assertNoJavaScriptErrors();
});
