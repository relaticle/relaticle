<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatBrowser;

it('keeps a rich-text field being edited, and its save button, inside the docked card', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'note proposal', $conversationId);

    PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => \App\Actions\Note\CreateNote::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'note',
        'action_data' => ['title' => 'Call HQ about invoice', 'custom_fields' => ['body' => '<p>Call HQ about the overdue invoice.</p>']],
        'display_data' => [
            'title' => 'Create Note',
            'summary' => 'Create note "Call HQ about invoice"',
            'fields' => [
                ['label' => 'Title', 'value' => 'Call HQ about invoice'],
                ['label' => 'Body', 'code' => 'body', 'new' => 'Call HQ about the overdue invoice.', 'type' => 'text'],
            ],
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->resize(1440, 900)
        ->assertSee('Call HQ about invoice')
        ->click('[aria-label="Edit Body"]')
        ->assertVisible('.fi-fo-rich-editor .ProseMirror');

    $layout = $page->script(<<<'JS'
        (() => {
            const buttons = Array.from(document.querySelectorAll('button'));
            const save = buttons.find((el) => el.textContent.trim() === 'Save').getBoundingClientRect();
            const footer = buttons.find((el) => el.textContent.trim() === 'Discard').parentElement.getBoundingClientRect();
            const editor = document.querySelector('.fi-fo-rich-editor').getBoundingClientRect();

            return {
                editorAboveFooter: editor.bottom <= footer.top,
                saveAboveFooter: save.bottom <= footer.top,
            };
        })();
    JS);

    expect($layout['editorAboveFooter'])->toBeTrue()
        ->and($layout['saveAboveFooter'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
