<?php

declare(strict_types=1);

namespace App\Filament\Pages\Import;

use App\Filament\Resources\NoteResource;

final class ImportNotes extends ImportPage
{
    protected static ?string $slug = 'notes/import';

    public static function getEntityType(): string
    {
        return 'notes';
    }

    public static function getResourceClass(): string
    {
        return NoteResource::class;
    }
}
