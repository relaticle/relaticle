<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\NoteResource;
use Relaticle\ImportWizard\Enums\ImportEntityType;

final class ImportNotes extends ImportPage
{
    protected static ?string $slug = 'notes/import';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::Note;
    }

    public static function getResourceClass(): string
    {
        return NoteResource::class;
    }
}
