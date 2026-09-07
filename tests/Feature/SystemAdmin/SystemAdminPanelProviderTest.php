<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ListCompanies as ListAppCompanies;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ListCompanies as ListSystemAdminCompanies;
use Relaticle\SystemAdmin\SystemAdminPanelProvider;

mutates(SystemAdminPanelProvider::class);

it('lets administrators reorder sysadmin table columns', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $table = Table::make(new ListSystemAdminCompanies);

    expect($table->hasReorderableColumns())->toBeTrue();
});

it('lets administrators toggle every sysadmin table column', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $column = TextColumn::make('name');

    expect($column->isToggleable())->toBeTrue();
});

it('leaves customer table column management unchanged', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $table = Table::make(new ListAppCompanies);
    $column = TextColumn::make('name');

    expect($table->hasReorderableColumns())->toBeFalse()
        ->and($column->isToggleable())->toBeFalse();
});
