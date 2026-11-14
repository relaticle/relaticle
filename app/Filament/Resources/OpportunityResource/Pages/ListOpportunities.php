<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\OpportunityResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;
use Override;

final class ListOpportunities extends ListRecords
{
    use HasCustomFieldColumns;
    use HasViewSwitcher;

    protected static string $resource = OpportunityResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return OpportunityResource::collectionActions();
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
