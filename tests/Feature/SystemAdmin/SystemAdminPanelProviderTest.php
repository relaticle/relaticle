<?php

declare(strict_types=1);

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

it('leaves customer table columns un-toggleable', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $column = TextColumn::make('name');

    expect($column->isToggleable())->toBeFalse();
});
