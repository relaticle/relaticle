<?php

declare(strict_types=1);

use App\Models\User;

it('closes the model picker when the user presses Escape', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/chats")
        ->click('[data-chat-context="dashboard"] [aria-label="Select AI model"]')
        ->assertVisible('[data-chat-context="dashboard"] [role="listbox"][aria-label="AI model options"]')
        ->keys('[data-chat-context="dashboard"] [aria-label="Select AI model"]', 'Escape')
        ->assertMissing('[data-chat-context="dashboard"] [role="listbox"][aria-label="AI model options"]');
});

it('reopens the model picker after Escape closes it', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/chats")
        ->click('[data-chat-context="dashboard"] [aria-label="Select AI model"]')
        ->keys('[data-chat-context="dashboard"] [aria-label="Select AI model"]', 'Escape')
        ->click('[data-chat-context="dashboard"] [aria-label="Select AI model"]')
        ->assertVisible('[data-chat-context="dashboard"] [role="listbox"][aria-label="AI model options"]');
});

it('shows a provider icon on the model picker button for a named model and on every option', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $buttonIcon = '[data-chat-context="dashboard"] [aria-label="Select AI model"] > span[aria-hidden="true"]';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/chats")
        ->assertMissing($buttonIcon);

    $page->script(<<<'JS'
        (() => {
            const scope = Alpine.$data(document.querySelector('[data-chat-context="dashboard"] [aria-label="Select AI model"]'));
            scope.selectedModel = scope.modelOptions.find((option) => option.value !== 'auto').value;
        })()
        JS);

    $page->assertVisible("{$buttonIcon} svg")
        ->click('[data-chat-context="dashboard"] [aria-label="Select AI model"]')
        ->assertVisible('[data-chat-context="dashboard"] [role="listbox"][aria-label="AI model options"]')
        ->assertScript(<<<'JS'
            (() => {
                const options = [...document.querySelectorAll('[data-chat-context="dashboard"] [role="option"]')];

                return options.length > 0 && options.every((option) => {
                    const icon = option.querySelector('span[aria-hidden="true"] svg');

                    return icon !== null && icon.getBoundingClientRect().width > 0;
                });
            })()
        JS);
});
