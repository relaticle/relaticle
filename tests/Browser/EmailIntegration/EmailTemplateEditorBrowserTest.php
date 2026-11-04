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
