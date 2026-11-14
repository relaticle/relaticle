<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Livewire\TemplatesTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(TemplatesTable::class);

function userWithMailbox(): User
{
    $user = User::factory()->withWorkspace()->create();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'user_id' => $user->id,
        'status' => 'active',
    ]));

    return $user;
}

function templatesTabPath(Workspace $workspace): string
{
    return "/app/{$workspace->slug}/email?tab=templates";
}

it('wraps the template body toolbar onto a phone screen', function (): void {
    $this->withVite();

    $user = userWithMailbox();
    $workspace = $user->currentWorkspace;

    visitAs($user, templatesTabPath($workspace), 440, 956)
        ->click(__('filament/resources/email-template.actions.create.label'))
        ->assertVisible('.fi-modal-open .fi-fo-rich-editor-toolbar')
        ->assertScript("(() => { const toolbar = document.querySelector('.fi-modal-open .fi-fo-rich-editor-toolbar'); return toolbar.scrollWidth <= toolbar.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

it('opens the variable picker while typing and inserts a variable on a phone', function (): void {
    $this->withVite();

    $user = userWithMailbox();
    $workspace = $user->currentWorkspace;

    visitAs($user, templatesTabPath($workspace), 393, 852)
        ->click(__('filament/resources/email-template.actions.create.label'))
        ->assertVisible('.fi-modal-open .fi-fo-rich-editor-toolbar')
        ->assertMissing('.ei-command-panel')
        ->type('.fi-modal-open [contenteditable="true"]', '{person.first_name')
        ->assertVisible('.ei-command-panel')
        ->click('.ei-command-panel [role="option"]')
        ->assertVisible('.fi-modal-open [data-type="emailVariable"][data-code="person.first_name"]')
        ->assertNoJavaScriptErrors();
});
