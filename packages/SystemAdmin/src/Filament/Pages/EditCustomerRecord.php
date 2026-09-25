<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Override;
use Relaticle\SystemAdmin\Actions\UpdateCustomerRecord;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

abstract class EditCustomerRecord extends EditRecord
{
    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof SystemAdministrator, 403);

        return resolve(UpdateCustomerRecord::class)->execute($actor, $record, $data);
    }
}
