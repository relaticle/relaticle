<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SavedViewType: string implements HasLabel
{
    case Table = 'table';
    case Board = 'board';

    public function getLabel(): string
    {
        return __("filament/panel.tables.views.types.{$this->value}");
    }
}
