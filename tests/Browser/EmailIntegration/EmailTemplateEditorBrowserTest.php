<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;

mutates(ManageEmailTemplates::class);

it('wraps the template body toolbar onto a phone screen', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(440, 956)
        ->navigate("/app/{$workspace->slug}/workspace/email/templates")
        ->click('.fi-header .fi-ac-btn-action')
        ->assertVisible('.fi-modal-open .fi-fo-rich-editor-toolbar')
        ->assertScript("(() => { const toolbar = document.querySelector('.fi-modal-open .fi-fo-rich-editor-toolbar'); return toolbar.scrollWidth <= toolbar.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

it('starts the merge tags panel closed and opens it from the toolbar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/workspace/email/templates")
        ->click('.fi-header .fi-ac-btn-action')
        ->assertVisible('.fi-modal-open .fi-fo-rich-editor-toolbar')
        ->assertMissing('.fi-modal-open .fi-fo-rich-editor-panels')
        ->click('.fi-modal-open [data-rich-editor-tool="mergeTags"]')
        ->assertVisible('.fi-modal-open .fi-fo-rich-editor-panels')
        ->assertNoJavaScriptErrors();
});
