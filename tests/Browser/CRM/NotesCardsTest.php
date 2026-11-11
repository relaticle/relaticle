<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;

it('draws a note card with no row menu or checkbox and opens the note for editing from the card', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $note = Note::factory()->recycle([$user, $workspace])->create(['title' => 'Renewal call notes']);

    $card = '.fi-ta-content-grid .fi-ta-record';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes?view=cards")
        ->hover($card)
        ->assertSeeIn($card, 'Renewal call notes')
        ->assertScript("document.querySelectorAll('{$card} .fi-dropdown-trigger, {$card} .fi-ta-record-checkbox').length", 0)
        ->assertMissing('.fi-bulk-bar')
        ->click($card)
        ->wait(1)
        ->assertSee('Edit Renewal call notes')
        ->assertScript('document.querySelector(".fi-modal input[type=text]").value', $note->title)
        ->assertNoJavaScriptErrors();
});
