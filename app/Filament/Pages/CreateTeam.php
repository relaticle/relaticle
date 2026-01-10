<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Override;

final class CreateTeam extends RegisterTenant
{
    #[Override]
    public static function getLabel(): string
    {
        return 'Create Team';
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name'),
            ]);
    }

    #[Override]
    protected function handleRegistration(array $data): Model
    {
        return resolve(\App\Actions\Jetstream\CreateTeam::class)->create(auth('web')->user(), $data);
    }
}
