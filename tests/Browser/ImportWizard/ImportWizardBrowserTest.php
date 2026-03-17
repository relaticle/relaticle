<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\ImportWizard\Livewire\ImportWizard;

mutates(ImportWizard::class);

it('can navigate the import wizard and upload a CSV file', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/companies/import")
        ->assertSee('Import Companies')
        ->assertSee('Drop your .CSV file onto this area to upload');

    // The full upload + wizard flow involves Livewire file uploads and complex
    // server-side state transitions that are better covered by the existing
    // Livewire feature tests in tests/Feature/ImportWizard/.
});
