<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Size;

final class ImportExportActionGroup extends ActionGroup
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('heroicon-o-arrows-up-down');

        $this->color('gray');

        $this->button();

        $this->labeledFrom('sm');

        $this->dropdownPlacement('bottom');

        $this->size(Size::Small);
    }
}
