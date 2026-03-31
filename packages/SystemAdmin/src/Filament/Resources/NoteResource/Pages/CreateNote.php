<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource;

final class CreateNote extends CreateRecord
{
    protected static string $resource = NoteResource::class;
}
