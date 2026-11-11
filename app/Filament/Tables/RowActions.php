<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class RowActions
{
    // Row click, the preview button and `?task=` links mount these by name. The empty cell
    // left in place is the column that takes the width the data columns leave.
    /**
     * @param  list<Action>  $actions
     */
    public static function withoutMenu(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)->view('filament.app.tables.row-actions');
    }
}
