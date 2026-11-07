<?php

declare(strict_types=1);

use App\Filament\Components\Tables\RecordChipColumn;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Providers\Filament\AppPanelProvider;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

mutates(AppPanelProvider::class, RecordChipColumn::class);

const CELL_LABELS_SHOWN = '[...document.querySelectorAll(".fi-ta-row .fi-ta-cell-label")].some((label) => label.offsetParent !== null)';

const TABLE_SCROLLS_SIDEWAYS = '(() => { const content = document.querySelector(".fi-ta-content-ctn"); return content.scrollWidth > content.clientWidth; })()';

function seedListRows(User $user): void
{
    $workspace = $user->ownedWorkspaces()->first();

    $company = Company::factory()->recycle([$user, $workspace])->create();
    People::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);
    Opportunity::factory()->recycle([$user, $workspace])->create();
    Task::factory()->recycle([$user, $workspace])->create();

    Import::factory()->create([
        'workspace_id' => (string) $workspace->getKey(),
        'user_id' => (string) $user->getKey(),
        'status' => ImportStatus::Completed,
    ]);
}

it('stacks each list row into a card so no column sits off a phone screen', function (string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible('.fi-ta-row')
        ->assertScript(CELL_LABELS_SHOWN, true)
        ->assertScript(TABLE_SCROLLS_SIDEWAYS, false)
        ->assertNoJavaScriptErrors();
})->with(['tasks', 'companies', 'people', 'opportunities', 'import-history']);

it('keeps the list table header row on desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/tasks")
        ->assertVisible('.fi-ta-header-cell[data-column-name="title"]')
        ->assertScript(CELL_LABELS_SHOWN, false)
        ->assertNoJavaScriptErrors();
});

it('keeps a linked company chip inside its cell link', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/people")
        ->assertScript('document.querySelector(\'[class~="fi-ta-cell-company.name"] a.fi-ta-col .fi-record-chip\') !== null', true)
        ->assertNoJavaScriptErrors();
});
