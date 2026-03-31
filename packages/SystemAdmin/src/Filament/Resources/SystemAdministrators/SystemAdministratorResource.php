<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\CreateSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\EditSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\ListSystemAdministrators;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\ViewSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Schemas\SystemAdministratorForm;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Schemas\SystemAdministratorInfolist;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Tables\SystemAdministratorsTable;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use UnitEnum;

final class SystemAdministratorResource extends Resource
{
    protected static ?string $model = SystemAdministrator::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return SystemAdministratorForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SystemAdministratorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemAdministratorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemAdministrators::route('/'),
            'create' => CreateSystemAdministrator::route('/create'),
            'view' => ViewSystemAdministrator::route('/{record}'),
            'edit' => EditSystemAdministrator::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes();
    }
}
