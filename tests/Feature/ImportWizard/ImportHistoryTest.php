<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Locales;
use Filament\Facades\Filament;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;
use Relaticle\ImportWizard\Models\Import;

mutates(ImportHistory::class, ImportStatus::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('shows the import status in the user language', function (string $locale, ImportStatus $status, string $label): void {
    Import::factory()->create([
        'user_id' => $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'status' => $status,
    ]);
    Locales::apply($locale);

    livewire(ImportHistory::class)
        ->assertSeeText($label)
        ->assertDontSeeText($status->value);
})->with([
    'de completed' => ['de', ImportStatus::Completed, 'Abgeschlossen'],
    'es failed' => ['es', ImportStatus::Failed, 'Fallida'],
    'fr importing' => ['fr', ImportStatus::Importing, 'Import en cours'],
    'en completed' => ['en', ImportStatus::Completed, 'Completed'],
]);
