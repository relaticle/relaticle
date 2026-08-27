<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages;

use App\Models\Team;
use Filament\Resources\Pages\EditRecord;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Support\SafeDelete;

final class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SafeDelete::action(function (Team $record): void {
                resolve(DeletesTeams::class)->delete($record);
            }),
        ];
    }
}
