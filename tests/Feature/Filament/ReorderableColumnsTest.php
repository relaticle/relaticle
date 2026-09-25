<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Providers\Filament\AppPanelProvider;
use Filament\Facades\Filament;
use Filament\Tables\Table;

mutates(AppPanelProvider::class);

it('lets customers reorder table columns inside the app panel', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $table = Table::make(new ListCompanies);

    expect($table->hasReorderableColumns())->toBeTrue();
});
